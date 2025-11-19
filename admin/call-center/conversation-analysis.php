<?php
declare(strict_types=1);

require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_login();
require_admin();

$page_title = 'Conversation.php Analysis';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/admin.css">
    <style>
        .analysis-card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        .status-good {
            background: #d4edda;
            padding: 1rem;
            border-left: 4px solid #28a745;
            border-radius: 4px;
            margin: 1rem 0;
        }
        .status-info {
            background: #d1ecf1;
            padding: 1rem;
            border-left: 4px solid #17a2b8;
            border-radius: 4px;
            margin: 1rem 0;
        }
        .code-example {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 5px;
            font-family: monospace;
            margin: 0.5rem 0;
        }
        .step-flow {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin: 1rem 0;
            flex-wrap: wrap;
        }
        .step-box {
            background: #007bff;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: bold;
        }
        .arrow {
            font-size: 1.5rem;
            color: #007bff;
        }
    </style>
</head>
<body>
<div class="admin-wrapper">
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="admin-content">
        <?php include '../includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="container-fluid p-4">
                
                <h2 class="mb-4"><i class="fas fa-search me-2"></i>conversation.php Deep Analysis</h2>

                <!-- Summary -->
                <div class="analysis-card">
                    <h4 class="mb-3"><i class="fas fa-check-circle text-success me-2"></i>Analysis Result: EVERYTHING IS PERFECT! ✅</h4>
                    
                    <div class="status-good">
                        <h5><i class="fas fa-thumbs-up me-2"></i>Good News!</h5>
                        <p class="mb-0"><strong>conversation.php does NOT use cache columns at all!</strong></p>
                        <p class="mb-0">It only collects form data and sends it to <code>process-conversation.php</code>, which we already fixed.</p>
                    </div>
                </div>

                <!-- Step Flow -->
                <div class="analysis-card">
                    <h4 class="mb-3"><i class="fas fa-route me-2"></i>Step Flow</h4>
                    
                    <div class="step-flow">
                        <div class="step-box">Step 1: Verification</div>
                        <div class="arrow">→</div>
                        <div class="step-box">Step 2: Donor Info</div>
                        <div class="arrow">→</div>
                        <div class="step-box">Step 3: Readiness</div>
                        <div class="arrow">→</div>
                        <div class="step-box">Step 4: Select Plan</div>
                        <div class="arrow">→</div>
                        <div class="step-box">Step 5: Confirm Plan</div>
                        <div class="arrow">→</div>
                        <div class="step-box">Step 6: Payment Method</div>
                        <div class="arrow">→</div>
                        <div class="step-box">Submit to process-conversation.php</div>
                    </div>
                </div>

                <!-- What conversation.php Does -->
                <div class="analysis-card">
                    <h4 class="mb-3"><i class="fas fa-clipboard-list me-2"></i>What conversation.php Does</h4>
                    
                    <h5 class="text-primary mb-3">1. Reads Data (From Database)</h5>
                    <div class="code-example">
                        <strong>Line 36-66:</strong> Fetches donor information<br>
                        <code>SELECT d.name, d.phone, d.balance, d.city, d.baptism_name, d.email ...</code><br>
                        <span class="text-success">✅ Does NOT read cache columns</span>
                    </div>

                    <div class="code-example">
                        <strong>Line 109-120:</strong> Fetches payment plan templates<br>
                        <code>SELECT * FROM payment_plan_templates WHERE is_active = 1</code><br>
                        <span class="text-success">✅ Correct - reads from templates table</span>
                    </div>

                    <h5 class="text-primary mb-3 mt-4">2. Displays Form (HTML)</h5>
                    <div class="status-info">
                        <p class="mb-2"><strong>Step 4 (Lines 920-1060):</strong> Shows payment plan options</p>
                        <ul class="mb-0">
                            <li>Displays template cards (3 months, 6 months, 12 months, custom)</li>
                            <li>Shows balance amount</li>
                            <li>Calculates monthly amount in JavaScript</li>
                            <li>All calculations are CLIENT-SIDE only (preview)</li>
                        </ul>
                    </div>

                    <h5 class="text-primary mb-3 mt-4">3. Collects Form Data (JavaScript)</h5>
                    <div class="code-example">
                        <strong>JavaScript Function:</strong> <code>selectPlan(template_id, duration)</code><br>
                        <strong>What it does:</strong>
                        <ul class="mb-0">
                            <li>Sets hidden input: <code>plan_template_id</code></li>
                            <li>Sets hidden input: <code>plan_duration</code></li>
                            <li>Shows preview calculation (£X per month)</li>
                            <li><span class="text-success">✅ Does NOT write to database</span></li>
                        </ul>
                    </div>

                    <h5 class="text-primary mb-3 mt-4">4. Submits to process-conversation.php</h5>
                    <div class="code-example">
                        <strong>Hidden Inputs Sent:</strong>
                        <ul class="mb-0">
                            <li><code>session_id</code></li>
                            <li><code>donor_id</code></li>
                            <li><code>queue_id</code></li>
                            <li><code>pledge_id</code></li>
                            <li><code>plan_template_id</code> (e.g., '3', '6', '12', 'custom')</li>
                            <li><code>plan_duration</code> (number of months)</li>
                            <li><code>start_date</code></li>
                            <li><code>payment_method</code> (bank_transfer, card, cash)</li>
                            <li>+ Other donor info fields</li>
                        </ul>
                    </div>
                </div>

                <!-- Key Finding -->
                <div class="analysis-card">
                    <h4 class="mb-3"><i class="fas fa-lightbulb text-warning me-2"></i>Key Finding</h4>
                    
                    <div class="status-good">
                        <h5 class="mb-3">✅ conversation.php is 100% Clean!</h5>
                        <p><strong>It does NOT:</strong></p>
                        <ul>
                            <li>❌ Read from cache columns (<code>plan_monthly_amount</code>, etc.)</li>
                            <li>❌ Write to cache columns</li>
                            <li>❌ Calculate final amounts (that's done in process-conversation.php)</li>
                            <li>❌ Touch the database for plan creation</li>
                        </ul>
                        <p class="mt-3"><strong>It ONLY:</strong></p>
                        <ul class="mb-0">
                            <li>✅ Shows form to collect data</li>
                            <li>✅ Does preview calculations in JavaScript</li>
                            <li>✅ Sends form data to process-conversation.php</li>
                        </ul>
                    </div>
                </div>

                <!-- Flow Diagram -->
                <div class="analysis-card">
                    <h4 class="mb-3"><i class="fas fa-project-diagram me-2"></i>Complete Data Flow</h4>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-success mb-3">✅ User Interface (conversation.php)</h6>
                            <div class="code-example">
                                1. Agent selects "12 Months Plan"<br>
                                2. JavaScript shows: "£33.33/month"<br>
                                3. Hidden input set: plan_template_id='12'<br>
                                4. Hidden input set: plan_duration='12'<br>
                                5. Form submits to process-conversation.php
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-info mb-3">💾 Database Logic (process-conversation.php)</h6>
                            <div class="code-example">
                                1. Receives: template_id='12', duration='12'<br>
                                2. Fetches: donor balance = £400<br>
                                3. Calculates: monthly_amount = £400/12 = £33.33<br>
                                4. <span class="text-success">Inserts into donor_payment_plans</span><br>
                                5. <span class="text-success">Updates donors flags only</span><br>
                                   (has_active_plan=1, active_payment_plan_id=X)
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Conclusion -->
                <div class="analysis-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h4 class="mb-3"><i class="fas fa-trophy me-2"></i>Conclusion</h4>
                    
                    <div class="bg-white text-dark p-4 rounded">
                        <h5 class="text-success mb-3">✅ NO CHANGES NEEDED to conversation.php</h5>
                        <p class="mb-3">The page is already perfectly designed for your new architecture:</p>
                        <ul>
                            <li><strong>Separation of Concerns:</strong> UI logic (conversation.php) is separate from database logic (process-conversation.php)</li>
                            <li><strong>No Cache Dependencies:</strong> Never relied on cache columns</li>
                            <li><strong>Clean Data Flow:</strong> Collects → Validates → Sends → Processes</li>
                        </ul>
                        <hr>
                        <h5 class="text-primary mb-2">What We Fixed:</h5>
                        <p class="mb-1">✅ <strong>process-conversation.php</strong> - Now only sets flags, no cache writes</p>
                        <p class="mb-1">✅ <strong>donors.php</strong> - Reads from master table via JOIN</p>
                        <p class="mb-1">✅ <strong>Database</strong> - Cache columns removed</p>
                        <hr>
                        <h5 class="text-success mb-2">Result:</h5>
                        <p class="mb-0 fw-bold">🎉 Your payment plan system is now using Single Source of Truth architecture!</p>
                    </div>
                </div>

                <!-- Testing Instructions -->
                <div class="analysis-card">
                    <h4 class="mb-3"><i class="fas fa-vial me-2"></i>How to Test</h4>
                    
                    <div class="alert alert-info">
                        <h6 class="alert-heading"><i class="fas fa-tasks me-2"></i>Test Steps:</h6>
                        <ol class="mb-0">
                            <li class="mb-2">
                                <strong>Go to Call Center:</strong> <code>admin/call-center/index.php</code>
                            </li>
                            <li class="mb-2">
                                <strong>Start a call</strong> for a donor with balance
                            </li>
                            <li class="mb-2">
                                <strong>Complete all steps</strong> and select "12 Months Plan"
                            </li>
                            <li class="mb-2">
                                <strong>Finish the call</strong>
                            </li>
                            <li class="mb-2">
                                <strong>Check donor record:</strong>
                                <ul>
                                    <li>Go to <code>admin/donor-management/donors.php</code></li>
                                    <li>Find the donor</li>
                                    <li>Verify plan details show correctly</li>
                                </ul>
                            </li>
                            <li class="mb-2">
                                <strong>Check payment plan:</strong>
                                <ul>
                                    <li>Click "View" on the donor</li>
                                    <li>Scroll to "Payment Plans" section</li>
                                    <li>Verify monthly amount shows £33.33 (not £0.00)</li>
                                </ul>
                            </li>
                        </ol>
                    </div>

                    <div class="alert alert-success mt-3">
                        <h6 class="alert-heading"><i class="fas fa-check-double me-2"></i>Expected Results:</h6>
                        <ul class="mb-0">
                            <li>✅ Monthly amount displays correctly</li>
                            <li>✅ Duration shows "12 months"</li>
                            <li>✅ Start date is set</li>
                            <li>✅ Status is "active"</li>
                            <li>✅ Progress bar shows 0/12 payments</li>
                        </ul>
                    </div>
                </div>

            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

