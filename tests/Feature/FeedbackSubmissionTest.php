<?php

namespace Tests\Feature;

use App\Enums\FeedbackReason;
use App\Enums\FeedbackStatus;
use App\Enums\UserRole;
use App\Mail\FeedbackCompletedMail;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\FeedbackSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class FeedbackSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_submit_feedback_with_attachment(): void
    {
        Storage::fake('local');

        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);
        $client = User::factory()->create(['role' => UserRole::Client]);
        $client->companies()->attach($company->id);
        $dashboard->users()->attach($client->id);

        $response = $this->actingAs($client)
            ->post(route('feedback.store'), [
                'reason' => FeedbackReason::DataWrong->value,
                'message' => 'Revenue on the dashboard does not match Shopify.',
                'page_url' => '/main',
                'client_dashboard_id' => $dashboard->id,
                'attachments' => [
                    UploadedFile::fake()->image('screenshot.png'),
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Thanks — your feedback was sent to the team.');

        $submission = FeedbackSubmission::query()->first();

        $this->assertNotNull($submission);
        $this->assertSame($client->id, $submission->user_id);
        $this->assertSame($dashboard->id, $submission->client_dashboard_id);
        $this->assertSame(FeedbackReason::DataWrong, $submission->reason);
        $this->assertSame(FeedbackStatus::Pending, $submission->status);
        $this->assertCount(1, $submission->attachments);
    }

    public function test_guest_cannot_submit_feedback(): void
    {
        $this->post(route('feedback.store'), [
            'reason' => FeedbackReason::Other->value,
            'message' => 'Something happened here today.',
        ])->assertRedirect(route('login'));
    }

    public function test_admin_can_review_feedback_and_download_attachment(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['role' => UserRole::Client]);

        $submission = FeedbackSubmission::query()->create([
            'user_id' => $user->id,
            'reason' => FeedbackReason::Confused->value,
            'message' => 'I do not understand the ROAS widget.',
            'status' => FeedbackStatus::Pending,
        ]);

        $path = 'feedback/'.$submission->id.'/note.txt';
        Storage::disk('local')->put($path, 'helpful context');

        $attachment = $submission->attachments()->create([
            'original_filename' => 'note.txt',
            'storage_path' => $path,
            'mime_type' => 'text/plain',
            'size_bytes' => 17,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.feedback.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Feedback/Index')
                ->where('pending_count', 1)
            );

        $this->actingAs($admin)
            ->post(route('admin.feedback.update', $submission), [
                'admin_notes' => 'Follow up with client on ROAS definition.',
                'mark_reviewed' => true,
            ])
            ->assertRedirect(route('admin.feedback.show', $submission))
            ->assertSessionHas('status', 'Feedback marked reviewed. No email was sent.');

        $submission->refresh();

        $this->assertSame(FeedbackStatus::Reviewed, $submission->status);
        $this->assertSame($admin->id, $submission->reviewed_by_user_id);
        $this->assertNotNull($submission->reviewed_at);

        $this->actingAs($admin)
            ->get(route('admin.feedback.attachments.download', $attachment))
            ->assertOk();

        $imagePath = 'feedback/'.$submission->id.'/screenshot.png';
        Storage::disk('local')->put($imagePath, 'fake-image');

        $imageAttachment = $submission->attachments()->create([
            'original_filename' => 'screenshot.png',
            'storage_path' => $imagePath,
            'mime_type' => 'image/png',
            'size_bytes' => 11,
        ]);

        $this->actingAs($admin)
            ->get(URL::temporarySignedRoute(
                'admin.feedback.attachments.show',
                now()->addHour(),
                ['attachment' => $imageAttachment->id],
            ))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_admin_feedback_show_embeds_inline_image_preview_src(): void
    {
        Storage::fake('local');
        config(['titan.feedback.disk' => 'local']);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['role' => UserRole::Client]);

        $submission = FeedbackSubmission::query()->create([
            'user_id' => $user->id,
            'reason' => FeedbackReason::DataWrong->value,
            'message' => 'Chart looks wrong.',
            'status' => FeedbackStatus::Pending,
        ]);

        $path = 'feedback/'.$submission->id.'/screenshot.png';
        Storage::disk('local')->put($path, 'fake-image-bytes');

        $submission->attachments()->create([
            'original_filename' => 'screenshot.png',
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'size_bytes' => 16,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.feedback.show', $submission))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Feedback/Show')
                ->where('submission.attachments.0.preview_src', fn ($src) => str_starts_with((string) $src, 'data:image/png;base64,'))
                ->where('submission.attachments.0.is_image', true)
            );
    }

    public function test_admin_can_mark_feedback_completed_and_notify_user(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create([
            'role' => UserRole::Client,
            'email' => 'client@example.com',
        ]);

        $submission = FeedbackSubmission::query()->create([
            'user_id' => $user->id,
            'reason' => FeedbackReason::Other->value,
            'message' => 'The export button does nothing.',
            'status' => FeedbackStatus::Pending,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.feedback.update', $submission), [
                'admin_notes' => 'Fixed in release 1.2.',
                'completion_message' => 'The export button fix is live in today\'s release.',
                'mark_completed' => true,
            ])
            ->assertRedirect(route('admin.feedback.show', $submission))
            ->assertSessionHas('status', 'Feedback marked complete and the user was notified by email.');

        $submission->refresh();

        $this->assertSame(FeedbackStatus::Completed, $submission->status);
        $this->assertSame($admin->id, $submission->completed_by_user_id);
        $this->assertNotNull($submission->completed_at);
        $this->assertNotNull($submission->reviewed_at);
        $this->assertSame('The export button fix is live in today\'s release.', $submission->completion_message);

        Mail::assertSent(FeedbackCompletedMail::class, function (FeedbackCompletedMail $mail) use ($submission) {
            return $mail->hasTo('client@example.com')
                && str_contains($mail->render(), 'The export button does nothing.')
                && str_contains($mail->render(), 'The export button fix is live in today\'s release.');
        });
    }

    public function test_marking_reviewed_does_not_send_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['role' => UserRole::Client]);

        $submission = FeedbackSubmission::query()->create([
            'user_id' => $user->id,
            'reason' => FeedbackReason::Confused->value,
            'message' => 'What does this chart mean?',
            'status' => FeedbackStatus::Pending,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.feedback.update', $submission), [
                'mark_reviewed' => true,
            ])
            ->assertRedirect(route('admin.feedback.show', $submission))
            ->assertSessionHas('status', 'Feedback marked reviewed. No email was sent.');

        $submission->refresh();

        $this->assertSame(FeedbackStatus::Reviewed, $submission->status);

        Mail::assertNothingSent();
    }

    public function test_marking_completed_twice_does_not_resend_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['role' => UserRole::Client]);

        $submission = FeedbackSubmission::query()->create([
            'user_id' => $user->id,
            'reason' => FeedbackReason::Other->value,
            'message' => 'General suggestion.',
            'status' => FeedbackStatus::Completed,
            'completed_at' => now(),
            'completed_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.feedback.update', $submission), [
                'mark_completed' => true,
            ])
            ->assertRedirect(route('admin.feedback.show', $submission));

        Mail::assertNothingSent();
    }

    public function test_client_cannot_access_admin_feedback_pages(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->actingAs($client)
            ->get(route('admin.feedback.index'))
            ->assertForbidden();
    }
}
