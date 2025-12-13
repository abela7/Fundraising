<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/auth.php';
require_once __DIR__ . '/../../../config/db.php';

require_login();
require_admin();

$page_title = 'Bulk Message History';
$db = db();

function tableExists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare("SHOW TABLES LIKE ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function buildUnionSql(mysqli $db, string $filterSql): array
{
    $parts = [];

    if (tableExists($db, 'sms_log')) {
        $parts[] = "
            SELECT
                'sms' AS channel,
                donor_id,
                phone_number AS phone,
                template_id,
                message_content,
                status,
                error_message,
                source_type,
                sent_at
            FROM sms_log
            WHERE {$filterSql}
        ";
    }

    if (tableExists($db, 'whatsapp_log')) {
        $parts[] = "
            SELECT
                'whatsapp' AS channel,
                donor_id,
                phone_number AS phone,
                template_id,
                message_content,
                status,
                error_message,
                source_type,
                sent_at
            FROM whatsapp_log
            WHERE {$filterSql}
        ";
    }

    if (empty($parts)) {
        return ['', []];
    }

    return [implode(' UNION ALL ', $parts), []];
}

// AJAX endpoints
if (isset($_GET['ajax']) && $_GET['ajax'] !== '') {
    header('Content-Type: application/json');

    try {
        $ajax = (string)$_GET['ajax'];

        if ($ajax === 'runs') {
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            if ($limit < 1) $limit = 10;
            if ($limit > 50) $limit = 50;
            if ($offset < 0) $offset = 0;

            // Only bulk source types
            $filterSql = "source_type LIKE 'bulk_%'";
            [$unionSql] = buildUnionSql($db, $filterSql);

            if ($unionSql === '') {
                echo json_encode(['success' => true, 'runs' => [], 'has_more' => false, 'next_offset' => 0]);
                exit;
            }

            $sql = "
                SELECT
                    run_token,
                    kind,
                    started_at,
                    finished_at,
                    total_count,
                    sent_count,
                    failed_count,
                    whatsapp_count,
                    sms_count,
                    template_id,
                    message_preview
                FROM (
                    SELECT
                        -- If source_type already contains a run id (bulk_xxx:bm_...), use it as token
                        CASE
                            WHEN INSTR(source_type, ':') > 0 THEN source_type
                            ELSE CONCAT(source_type, ':legacy-', DATE_FORMAT(sent_at, '%Y%m%d%H%i'))
                        END AS run_token,
                        CASE
                            WHEN INSTR(source_type, ':') > 0 THEN SUBSTRING_INDEX(source_type, ':', 1)
                            ELSE source_type
                        END AS kind,
                        MIN(sent_at) AS started_at,
                        MAX(sent_at) AS finished_at,
                        COUNT(*) AS total_count,
                        SUM(CASE WHEN status IN ('sent','delivered') THEN 1 ELSE 0 END) AS sent_count,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                        SUM(CASE WHEN channel = 'whatsapp' THEN 1 ELSE 0 END) AS whatsapp_count,
                        SUM(CASE WHEN channel = 'sms' THEN 1 ELSE 0 END) AS sms_count,
                        MAX(template_id) AS template_id,
                        LEFT(MIN(message_content), 140) AS message_preview
                    FROM (
                        {$unionSql}
                    ) x
                    GROUP BY run_token, kind
                ) grouped
                ORDER BY started_at DESC
                LIMIT ? OFFSET ?
            ";

            $stmt = $db->prepare($sql);
            $fetchLimit = $limit + 1;
            $stmt->bind_param('ii', $fetchLimit, $offset);
            $stmt->execute();
            $res = $stmt->get_result();

            $runs = [];
            while ($row = $res->fetch_assoc()) {
                $runs[] = $row;
            }

            $hasMore = count($runs) > $limit;
            if ($hasMore) array_pop($runs);

            echo json_encode([
                'success' => true,
                'runs' => $runs,
                'has_more' => $hasMore,
                'next_offset' => $offset + count($runs),
            ]);
            exit;
        }

        if ($ajax === 'run_details') {
            $runToken = isset($_GET['run_token']) ? (string)$_GET['run_token'] : '';
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            if ($limit < 1) $limit = 25;
            if ($limit > 100) $limit = 100;
            if ($offset < 0) $offset = 0;

            if ($runToken === '') {
                throw new Exception('Missing run token');
            }

            // Legacy token: kind:legacy-YYYYMMDDHHMM
            $filterSql = '';
            $params = [];
            $types = '';

            if (strpos($runToken, ':legacy-') !== false) {
                [$kind, $legacyPart] = explode(':legacy-', $runToken, 2);
                $legacyMinute = preg_replace('/[^0-9]/', '', $legacyPart);
                if (strlen($legacyMinute) !== 12) {
                    throw new Exception('Invalid legacy token');
                }

                $start = DateTime::createFromFormat('YmdHi', $legacyMinute);
                if (!$start) {
                    throw new Exception('Invalid legacy token');
                }
                $end = clone $start;
                $end->modify('+59 seconds');

                $filterSql = "source_type = ? AND sent_at BETWEEN ? AND ?";
                $params = [$kind, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
                $types = 'sss';
            } else {
                $filterSql = "source_type = ?";
                $params = [$runToken];
                $types = 's';
            }

            [$unionSql] = buildUnionSql($db, $filterSql);
            if ($unionSql === '') {
                echo json_encode(['success' => true, 'items' => [], 'has_more' => false, 'next_offset' => 0]);
                exit;
            }

            $sql = "
                SELECT
                    x.channel,
                    x.donor_id,
                    x.phone,
                    x.template_id,
                    x.status,
                    x.error_message,
                    x.sent_at,
                    d.name AS donor_name
                FROM (
                    {$unionSql}
                ) x
                LEFT JOIN donors d ON x.donor_id = d.id
                ORDER BY x.sent_at DESC
                LIMIT ? OFFSET ?
            ";

            $stmt = $db->prepare($sql);
            $fetchLimit = $limit + 1;

            // Bind params + limit/offset
            $bindParams = [$types . 'ii'];
            $refs = [];
            foreach ($params as $k => $v) {
                $refs[$k] = $v;
                $bindParams[] = &$refs[$k];
            }
            $refs[] = $fetchLimit;
            $refs[] = $offset;
            $bindParams[] = &$refs[count($refs) - 2];
            $bindParams[] = &$refs[count($refs) - 1];

            call_user_func_array([$stmt, 'bind_param'], $bindParams);
            $stmt->execute();
            $res = $stmt->get_result();

            $items = [];
            while ($row = $res->fetch_assoc()) {
                $items[] = $row;
            }

            $hasMore = count($items) > $limit;
            if ($hasMore) array_pop($items);

            echo json_encode([
                'success' => true,
                'items' => $items,
                'has_more' => $hasMore,
                'next_offset' => $offset + count($items),
            ]);
            exit;
        }

        throw new Exception('Invalid action');
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Fundraising Admin</title>
    <link rel="icon" type="image/svg+xml" href="../../../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../../assets/admin.css">
    <link rel="stylesheet" href="../assets/donor-management.css">
    <style>
        :root {
            --brand: #0a6286;
            --brand2: #0ea5e9;
            --border: #e2e8f0;
            --muted: #64748b;
        }
        .run-card {
            border: 1px solid var(--border);
            border-radius: 14px;
            background: #fff;
        }
        .run-card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            transform: translateY(-1px);
            transition: all 0.2s;
        }
        .mono {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }
        @media (max-width: 575.98px) {
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../../includes/sidebar.php'; ?>
    <div class="admin-content">
        <?php include '../../includes/topbar.php'; ?>
        <main class="main-content">
            <div class="container-fluid p-3 p-md-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-4">
                    <div>
                        <h1 class="h3 mb-1"><i class="fas fa-history me-2 text-primary"></i>Bulk Message History</h1>
                        <p class="text-muted mb-0">View previous bulk sends and recipient results.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-outline-secondary" href="bulk-message.php">
                            <i class="fas fa-arrow-left me-2"></i>Back to Bulk Messaging
                        </a>
                    </div>
                </div>

                <div id="runsList"></div>

                <div class="d-flex justify-content-center py-3">
                    <button class="btn btn-outline-primary d-none" id="btnLoadMoreRuns">Load more</button>
                </div>

                <div id="runsEmpty" class="text-center text-muted py-5 d-none">
                    <i class="fas fa-inbox fa-2x mb-2"></i>
                    <div>No bulk history found yet.</div>
                    <div class="small">Send a bulk message first, then come back here.</div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="runDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-1">Bulk Run Details</h5>
                    <div class="small text-muted mono" id="detailRunToken">-</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded">
                            <div class="text-muted small">Total</div>
                            <div class="h4 mb-0" id="detailTotal">0</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded">
                            <div class="text-muted small">Sent</div>
                            <div class="h4 mb-0 text-success" id="detailSent">0</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded">
                            <div class="text-muted small">Failed</div>
                            <div class="h4 mb-0 text-danger" id="detailFailed">0</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 border rounded">
                            <div class="text-muted small">Channels</div>
                            <div class="small">
                                <span class="badge bg-light text-dark border me-1" id="detailWhatsApp">WA: 0</span>
                                <span class="badge bg-light text-dark border" id="detailSMS">SMS: 0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="alert alert-light border mb-3">
                    <div class="small text-muted mb-1">Message preview</div>
                    <div id="detailMessagePreview">-</div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Recipients</h6>
                    <span class="small text-muted" id="detailRecipientsHint"></span>
                </div>

                <div id="recipientsList"></div>
                <div class="d-flex justify-content-center py-3">
                    <button class="btn btn-outline-primary d-none" id="btnLoadMoreRecipients">Load more</button>
                </div>
                <div id="recipientsEmpty" class="text-center text-muted py-4 d-none">
                    No recipients found for this run.
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/admin.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const runsList = document.getElementById('runsList');
    const runsEmpty = document.getElementById('runsEmpty');
    const btnLoadMoreRuns = document.getElementById('btnLoadMoreRuns');

    const modalEl = document.getElementById('runDetailModal');
    const modal = new bootstrap.Modal(modalEl);

    const detailRunToken = document.getElementById('detailRunToken');
    const detailTotal = document.getElementById('detailTotal');
    const detailSent = document.getElementById('detailSent');
    const detailFailed = document.getElementById('detailFailed');
    const detailWhatsApp = document.getElementById('detailWhatsApp');
    const detailSMS = document.getElementById('detailSMS');
    const detailMessagePreview = document.getElementById('detailMessagePreview');
    const detailRecipientsHint = document.getElementById('detailRecipientsHint');
    const recipientsList = document.getElementById('recipientsList');
    const recipientsEmpty = document.getElementById('recipientsEmpty');
    const btnLoadMoreRecipients = document.getElementById('btnLoadMoreRecipients');

    let runsOffset = 0;
    const runsLimit = 10;
    let runsHasMore = false;
    let runsLoading = false;

    let currentRun = null;
    let recipientsOffset = 0;
    const recipientsLimit = 25;
    let recipientsHasMore = false;
    let recipientsLoading = false;

    loadRuns(false);
    btnLoadMoreRuns.addEventListener('click', () => loadRuns(true));
    btnLoadMoreRecipients.addEventListener('click', () => loadRecipients(true));

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    async function loadRuns(append) {
        if (runsLoading) return;
        runsLoading = true;
        btnLoadMoreRuns.disabled = true;

        if (!append) {
            runsOffset = 0;
            runsList.innerHTML = '';
            runsEmpty.classList.add('d-none');
        }

        try {
            const url = new URL(window.location.href);
            url.searchParams.set('ajax', 'runs');
            url.searchParams.set('limit', String(runsLimit));
            url.searchParams.set('offset', String(runsOffset));

            const res = await fetch(url.toString());
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Failed to load runs');

            const runs = data.runs || [];
            if (runs.length === 0 && runsOffset === 0) {
                runsEmpty.classList.remove('d-none');
                btnLoadMoreRuns.classList.add('d-none');
                return;
            }

            runs.forEach(r => {
                const started = r.started_at ? new Date(r.started_at).toLocaleString() : '';
                const kindLabel = r.kind || 'bulk';
                const preview = r.message_preview ? escapeHtml(r.message_preview) : '';
                const token = r.run_token;

                const card = document.createElement('div');
                card.className = 'run-card p-3 mb-3';
                card.innerHTML = `
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-2">
                        <div class="min-width-0">
                            <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                                <span class="badge bg-light text-dark border">${escapeHtml(kindLabel)}</span>
                                <span class="small text-muted"><i class="far fa-clock me-1"></i>${escapeHtml(started)}</span>
                            </div>
                            <div class="small text-muted mono text-truncate">${escapeHtml(token)}</div>
                            <div class="mt-2">${preview}${r.message_preview && r.message_preview.length >= 140 ? '…' : ''}</div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="badge bg-light text-dark border">Total: ${r.total_count}</span>
                            <span class="badge bg-success">Sent: ${r.sent_count}</span>
                            <span class="badge bg-danger">Failed: ${r.failed_count}</span>
                            <button class="btn btn-outline-primary" type="button" data-run-token="${escapeHtml(token)}">
                                View details
                            </button>
                        </div>
                    </div>
                `;
                card.querySelector('button[data-run-token]').addEventListener('click', () => openRunDetails(r));
                runsList.appendChild(card);
            });

            runsOffset = data.next_offset || (runsOffset + runs.length);
            runsHasMore = !!data.has_more;
            if (runsHasMore) {
                btnLoadMoreRuns.classList.remove('d-none');
                btnLoadMoreRuns.disabled = false;
                btnLoadMoreRuns.textContent = 'Load more';
            } else {
                btnLoadMoreRuns.classList.add('d-none');
            }
        } catch (e) {
            console.error(e);
        } finally {
            runsLoading = false;
        }
    }

    function badgeForStatus(status) {
        const s = (status || '').toLowerCase();
        if (s === 'failed') return 'bg-danger';
        if (s === 'delivered') return 'bg-success';
        if (s === 'sent') return 'bg-primary';
        return 'bg-secondary';
    }

    async function openRunDetails(run) {
        currentRun = run;
        recipientsOffset = 0;
        recipientsHasMore = false;
        recipientsList.innerHTML = '';
        recipientsEmpty.classList.add('d-none');
        btnLoadMoreRecipients.classList.add('d-none');

        detailRunToken.textContent = run.run_token || '-';
        detailTotal.textContent = run.total_count || 0;
        detailSent.textContent = run.sent_count || 0;
        detailFailed.textContent = run.failed_count || 0;
        detailWhatsApp.textContent = `WA: ${run.whatsapp_count || 0}`;
        detailSMS.textContent = `SMS: ${run.sms_count || 0}`;
        detailMessagePreview.textContent = run.message_preview || '-';
        detailRecipientsHint.textContent = 'Newest first';

        modal.show();
        await loadRecipients(false);
    }

    async function loadRecipients(append) {
        if (recipientsLoading || !currentRun) return;
        recipientsLoading = true;
        btnLoadMoreRecipients.disabled = true;

        if (!append) {
            recipientsOffset = 0;
            recipientsList.innerHTML = '';
        }

        try {
            const url = new URL(window.location.href);
            url.searchParams.set('ajax', 'run_details');
            url.searchParams.set('run_token', currentRun.run_token);
            url.searchParams.set('limit', String(recipientsLimit));
            url.searchParams.set('offset', String(recipientsOffset));

            const res = await fetch(url.toString());
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Failed to load recipients');

            const items = data.items || [];
            if (items.length === 0 && recipientsOffset === 0) {
                recipientsEmpty.classList.remove('d-none');
                btnLoadMoreRecipients.classList.add('d-none');
                return;
            }
            recipientsEmpty.classList.add('d-none');

            items.forEach(it => {
                const donorName = it.donor_name || 'Unknown';
                const donorId = it.donor_id || '';
                const profileLink = donorId ? `../view-donor.php?id=${encodeURIComponent(donorId)}` : '#';
                const status = it.status || '';
                const channel = it.channel || '';
                const err = it.error_message || '';
                const sentAt = it.sent_at ? new Date(it.sent_at).toLocaleString() : '';

                const row = document.createElement('div');
                row.className = 'p-3 border rounded mb-2';
                row.innerHTML = `
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="min-width-0">
                            <a href="${profileLink}" class="fw-semibold text-decoration-none" style="color: var(--brand); display:block;">
                                ${escapeHtml(donorName)}
                            </a>
                            <div class="small text-muted"><i class="fas fa-phone me-1"></i>${escapeHtml(it.phone || '')}</div>
                            <div class="small text-muted"><i class="far fa-clock me-1"></i>${escapeHtml(sentAt)}</div>
                            ${err ? `<div class="small text-danger mt-1">${escapeHtml(err)}</div>` : ''}
                        </div>
                        <div class="text-end flex-shrink-0">
                            <div class="badge ${badgeForStatus(status)} mb-1">${escapeHtml(status || 'unknown')}</div><br/>
                            <div class="badge bg-light text-dark border">${escapeHtml(channel)}</div>
                        </div>
                    </div>
                `;
                recipientsList.appendChild(row);
            });

            recipientsOffset = data.next_offset || (recipientsOffset + items.length);
            recipientsHasMore = !!data.has_more;
            if (recipientsHasMore) {
                btnLoadMoreRecipients.classList.remove('d-none');
                btnLoadMoreRecipients.disabled = false;
                btnLoadMoreRecipients.textContent = 'Load more';
            } else {
                btnLoadMoreRecipients.classList.add('d-none');
            }
        } catch (e) {
            console.error(e);
        } finally {
            recipientsLoading = false;
        }
    }
});
</script>
</body>
</html>

