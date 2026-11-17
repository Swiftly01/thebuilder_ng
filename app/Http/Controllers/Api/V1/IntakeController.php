<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Nominations\References;
use App\Http\Controllers\Controller;
use App\Http\Requests\IntakeRequest;
use App\Models\NewsletterSubscription;
use App\Models\Nomination;
use App\Models\StorySubmission;
use App\Notifications\TransactionalNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpFoundation\Response;

final class IntakeController extends Controller
{
    public function nominate(IntakeRequest $r, References $refs): Response
    {
        $item = DB::transaction(function () use ($r, $refs) {
            $data = $r->validated();
            foreach (['nomineeEmail' => 'nominee_email', 'nomineePhone' => 'nominee_phone', 'submitterName' => 'submitter_name', 'submitterEmail' => 'submitter_email'] as $a => $b) {
                if (isset($data[$a])) {
                    $data[$b] = $data[$a];
                    unset($data[$a]);
                }
            }
            unset($data['consent']);
            $item = Nomination::create($data + ['reference' => $refs->next('NOM'), 'consent_at' => now()]);
            Notification::route('mail', $item->submitter_email)->notify(new TransactionalNotice('Nomination received', 'Your nomination reference is '.$item->reference.'. The editorial team will review it.'));

            return $item;
        });

        return response()->json(['data' => ['reference' => $item->reference]], 201);
    }

    public function submit(IntakeRequest $r, References $refs): Response
    {
        $item = DB::transaction(function () use ($r, $refs) {
            $data = $r->validated();
            if (isset($data['whyNow'])) {
                $data['why_now'] = $data['whyNow'];
                unset($data['whyNow']);
            }
            $item = StorySubmission::create($data + ['user_id' => $r->user()->id, 'reference' => $refs->next('SUB')]);
            $r->user()->notify(new TransactionalNotice('Story idea received', 'Your submission reference is '.$item->reference.'. It will be reviewed privately.'));

            return $item;
        });

        return response()->json(['data' => ['reference' => $item->reference]], 201);
    }

    public function subscribe(IntakeRequest $r): Response
    {
        $data = $r->validated();
        DB::transaction(function () use ($data): void {
            $email = strtolower(trim($data['email']));
            $item = NewsletterSubscription::firstOrCreate(['email' => $email], ['edition' => $data['edition'] ?? config('builder.default_edition'), 'consent_at' => now()]);
            $item = NewsletterSubscription::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->status === 'confirmed') {
                return;
            }
            $token = bin2hex(random_bytes(32));
            $unsub = bin2hex(random_bytes(32));
            $item->update(['token_hash' => hash('sha256', $token), 'unsubscribe_hash' => hash('sha256', $unsub), 'token_expires_at' => now()->addDay(), 'status' => 'pending', 'consent_at' => now()]);
            Notification::route('mail', $email)->notify(new TransactionalNotice('Confirm your newsletter subscription', 'Confirm to receive The Builder Newsletter.', config('builder.frontend_url').'/newsletter?token='.$token.'&action=confirm'));
        });

        return response()->noContent(202);
    }

    public function confirm(IntakeRequest $r): Response
    {
        DB::transaction(function () use ($r): void {
            $item = NewsletterSubscription::where('token_hash', hash('sha256', $r->validated('token')))->where('token_expires_at', '>', now())->where('status', 'pending')->lockForUpdate()->firstOrFail();
            $unsubscribe = bin2hex(random_bytes(32));
            $item->update(['status' => 'confirmed', 'confirmed_at' => now(), 'token_hash' => null, 'unsubscribe_hash' => hash('sha256', $unsubscribe)]);
            Notification::route('mail', $item->email)->notify(new TransactionalNotice('Welcome to The Builder Newsletter', 'Your subscription is confirmed. You can manage it using this link.', config('builder.frontend_url').'/newsletter?action=unsubscribe&token='.$unsubscribe));
        });

        return response()->json(['data' => ['confirmed' => true]]);
    }

    public function unsubscribe(IntakeRequest $r): Response
    {
        $item = NewsletterSubscription::where('unsubscribe_hash', hash('sha256', $r->validated('token')))->firstOrFail();
        $item->update(['status' => 'unsubscribed']);

        return response()->noContent();
    }
}
