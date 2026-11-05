<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Registration;
use App\Services\RegistrationNotifier;

class SendRegistrationWhatsApp extends Command
{
    protected $signature = 'registrations:send-whatsapp';

    protected $description = 'Send pending registration WhatsApp messages (wa_send = 0) to the user and the store POC';

    const BATCH_SIZE    = 5;    // registrations picked up per loop
    const RUN_SECONDS   = 55;   // keep looping inside the one-minute schedule
    const IDLE_SECONDS  = 3;    // wait between loops when nothing is pending
    const MAX_ATTEMPTS  = 5;    // give up on a registration after this many failed tries
    const RETRY_SECONDS = 60;   // wait before retrying a failed registration

    public function handle()
    {
        $started = time();
        $sent = $failed = 0;

        while (time() - $started < self::RUN_SECONDS) {
            $pending = Registration::where('wa_send', 0)
                ->where('wa_attempts', '<', self::MAX_ATTEMPTS)
                ->where(function ($q) {
                    $q->where('wa_attempts', 0)->orWhere('updated_at', '<=', now()->subSeconds(self::RETRY_SECONDS));
                })
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->get();

            if ($pending->isEmpty()) {
                sleep(self::IDLE_SECONDS);
                continue;
            }

            foreach ($pending as $registration) {
                // claim it first so an overlapping run can never send the same registration twice
                $claimed = DB::table('registrations')
                    ->where('id', $registration->id)
                    ->where('wa_send', 0)
                    ->where('wa_attempts', $registration->wa_attempts)
                    ->update(['wa_attempts' => $registration->wa_attempts + 1, 'updated_at' => now()]);

                if (!$claimed) continue;

                try {
                    $ok = RegistrationNotifier::sendConfirmation($registration);
                } catch (\Throwable $e) {
                    Log::error("Registration {$registration->id} WhatsApp failed: " . $e->getMessage());
                    $ok = false;
                }

                if ($ok) {
                    DB::table('registrations')->where('id', $registration->id)->update(['wa_send' => 1]);
                    $sent++;
                } else {
                    $failed++;
                }
            }
        }

        $this->info("WhatsApp sent: {$sent}, failed (will retry): {$failed}");
    }
}
