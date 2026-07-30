<?php

declare(strict_types=1);

/**
 * CLI worker for WhatsApp certificate image jobs.
 *
 * cPanel cron runs this script outside LiteSpeed, where headless Chrome is
 * allowed to start. A non-blocking file lock prevents overlapping workers.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/UltraMsgService.php';
require_once __DIR__ . '/../shared/cert_token.php';
require_once __DIR__ . '/../shared/CertificateImageRenderer.php';
require_once __DIR__ . '/../shared/WhatsAppCertificateJobQueue.php';

set_time_limit(0);

$lockDir = dirname(__DIR__) . '/logs/.locks';
if (!is_dir($lockDir) && !@mkdir($lockDir, 0700, true)) {
    fwrite(STDERR, "Certificate worker lock directory is unavailable.\n");
    exit(1);
}
$lockPath = $lockDir . '/whatsapp-certificate-worker.lock';
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false) {
    fwrite(STDERR, "Certificate worker cannot open its lock file.\n");
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Certificate worker is already running.\n");
    exit(0);
}

/**
 * Resolve the public application origin used by headless Chrome.
 */
function certificate_worker_app_url(): string
{
    $candidates = [
        defined('CERT_APP_URL') ? (string)CERT_APP_URL : '',
        (string)getenv('CERT_APP_URL'),
        defined('APP_URL') ? (string)APP_URL : '',
        (string)getenv('APP_URL'),
        'https://donate.abuneteklehaymanot.org',
    ];

    foreach ($candidates as $candidate) {
        $candidate = rtrim(trim($candidate), '/');
        $scheme = strtolower((string)parse_url($candidate, PHP_URL_SCHEME));
        if (
            $candidate !== ''
            && in_array($scheme, ['http', 'https'], true)
            && filter_var($candidate, FILTER_VALIDATE_URL)
        ) {
            return $candidate;
        }
    }

    throw new RuntimeException('No valid certificate application URL.');
}

/**
 * Notify the requesting operator only after the final failed attempt.
 */
function notify_certificate_job_failure(
    ?UltraMsgService $service,
    array $job,
    string $error
): void {
    if ($service === null) {
        return;
    }

    $failurePhone = WhatsAppCertificateJobQueue::failurePhone($job);
    if ($failurePhone === '') {
        return;
    }

    $message = "❌ *ማረጋገጫውን መላክ አልተሳካም።*\n\n"
        . WhatsAppCertificateJobQueue::sanitizeFailure($error)
        . "\n\nእባክዎ ከአስተዳዳሪው ጋር ያገናኙ።";

    $service->send($failurePhone, $message, [
        'log' => true,
        'source_type' => 'whatsapp_certificate_worker',
        'user_id' => (int)$job['operator_user_id'],
    ]);
}

$db = db();
$queue = new WhatsAppCertificateJobQueue($db);
$service = UltraMsgService::fromDatabase($db);
if ($service === null) {
    fwrite(STDERR, "Certificate worker: UltraMsg is not configured.\n");
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(1);
}

$renderer = new CertificateImageRenderer();
$appUrl = certificate_worker_app_url();
$processed = 0;
$batchLimit = 10;

foreach ($queue->recoverStaleJobs() as $staleJob) {
    notify_certificate_job_failure(
        $service,
        $staleJob,
        (string)$staleJob['last_error']
    );
}

while ($processed < $batchLimit) {
    $job = $queue->claimNext();
    if ($job === null) {
        break;
    }

    $processed++;
    $jobId = (int)$job['id'];
    $lockToken = (string)$job['lock_token'];
    $donorId = (int)$job['donor_id'];
    $type = (string)$job['certificate_type'];
    $metadata = json_decode((string)($job['metadata_json'] ?? '{}'), true);
    if (!is_array($metadata)) {
        $metadata = [];
    }
    $outputPath = '';

    try {
        $viewToken = cert_render_token($donorId, 'view');
        $renderUrl = $appUrl
            . '/admin/donor-management/view-donor.php?id=' . $donorId
            . '&view_token=' . urlencode($viewToken)
            . '&cert_type=' . urlencode($type);

        $uploadDir = dirname(__DIR__) . '/uploads/certificates/' . date('Y/m');
        $outputPath = $uploadDir . '/cert_job_' . $jobId . '_'
            . date('Ymd_His') . '.png';

        $render = $renderer->render($renderUrl, $type, $outputPath);
        if (empty($render['success'])) {
            throw new RuntimeException(
                (string)($render['error'] ?? 'Certificate render failed.')
            );
        }

        $send = $service->sendImageFromFile(
            (string)$job['destination_phone'],
            $outputPath,
            (string)$job['caption']
        );
        if (empty($send['success'])) {
            throw new RuntimeException(
                'UltraMsg image send failed: '
                . (string)($send['error'] ?? 'unknown')
            );
        }

        $queue->markCompleted($jobId, $lockToken, [
            'message_id' => (string)($send['message_id'] ?? ''),
            'completed_by' => 'cli',
            'image_deleted' => true,
        ]);

        if (($metadata['source'] ?? '') === 'whatsapp_pay') {
            $staffPhone = WhatsAppCertificateJobQueue::failurePhone($job);
            if ($staffPhone !== '') {
                $noticeSent = false;
                for ($noticeAttempt = 1; $noticeAttempt <= 3; $noticeAttempt++) {
                    $notice = $service->send(
                        $staffPhone,
                        WhatsAppCertificateJobQueue::payStaffSuccessSummary(
                            $metadata
                        ),
                        [
                            'log' => true,
                            'source_type' => 'whatsapp_pay_delivery',
                            'user_id' => (int)$job['operator_user_id'],
                        ]
                    );
                    if (!empty($notice['success'])) {
                        $noticeSent = true;
                        break;
                    }
                    if ($noticeAttempt < 3) {
                        usleep(500000);
                    }
                }
                if (!$noticeSent) {
                    fwrite(
                        STDERR,
                        "Could not send PAY delivery notice after three "
                        . "attempts for job #{$jobId}.\n"
                    );
                }
            }
        }
        @unlink($outputPath);

        fwrite(STDOUT, "Completed certificate job #{$jobId}.\n");
    } catch (Throwable $e) {
        if ($outputPath !== '' && is_file($outputPath)) {
            @unlink($outputPath);
        }

        $error = WhatsAppCertificateJobQueue::sanitizeFailure($e->getMessage());
        try {
            $status = $queue->markFailed($jobId, $lockToken, $error);
        } catch (Throwable $markError) {
            fwrite(
                STDERR,
                "Could not record failure for job #{$jobId}: "
                . WhatsAppCertificateJobQueue::sanitizeFailure(
                    $markError->getMessage()
                ) . "\n"
            );
            continue;
        }

        fwrite(
            STDERR,
            "Certificate job #{$jobId} {$status}: {$error}\n"
        );
        if ($status === 'failed') {
            notify_certificate_job_failure($service, $job, $error);
        }
    }
}

fwrite(STDOUT, "Certificate worker processed {$processed} job(s).\n");
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
