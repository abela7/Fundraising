<?php
/**
 * Donor Portal - Update Pledge Amount
 * Allows donors to request an increase to their pledge amount
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Set up error handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("Donor update-pledge: FATAL ERROR - " . $error['message'] . " in " . $error['file'] . ":" . $error['line']);
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo "<!DOCTYPE html><html><head><title>Error</title></head><body>";
        echo "<h1>Fatal Error</h1>";
        echo "<p><strong>Message:</strong> " . htmlspecialchars($error['message']) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($error['file']) . "</p>";
        echo "<p><strong>Line:</strong> " . $error['line'] . "</p>";
        echo "</body></html>";
        exit;
    }
});

require_once __DIR__ . '/../shared/auth.php';
require_once __DIR__ . '/../shared/csrf.php';
require_once __DIR__ . '/../shared/url.php';
require_once __DIR__ . '/../admin/includes/resilient_db_loader.php';
require_once __DIR__ . '/../shared/GridAllocationBatchTracker.php';

function current_donor(): ?array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['donor'])) {
        return $_SESSION['donor'];
    }
    return null;
}

function require_donor_login(): void {
    if (!current_donor()) {
        header('Location: login.php');
        exit;
    }
}

try {
    require_donor_login();
    validate_donor_device(); // Check if device was revoked
    $donor = current_donor();
    if (!$donor) {
        header('Location: login.php');
        exit;
    }
    
    // Refresh donor data from database to ensure latest values
    if ($donor && isset($db_connection_ok) && $db_connection_ok && isset($db) && $db instanceof mysqli) {
        try {
            $email_check = $db->query("SHOW COLUMNS FROM donors LIKE 'email'");
            $has_email = $email_check->num_rows > 0;
            $email_check->close();
            
            $email_opt_in_check = $db->query("SHOW COLUMNS FROM donors LIKE 'email_opt_in'");
            $has_email_opt_in = $email_opt_in_check->num_rows > 0;
            $email_opt_in_check->close();
            
            $select_fields = "id, name, phone, total_pledged, total_paid, balance, 
                       has_active_plan, active_payment_plan_id, plan_monthly_amount,
                       plan_duration_months, plan_start_date, plan_next_due_date,
                       payment_status, preferred_payment_method, preferred_language";
            if ($has_email) {
                $select_fields .= ", email";
            }
            if ($has_email_opt_in) {
                $select_fields .= ", email_opt_in";
            }
            
            $refresh_stmt = $db->prepare("SELECT $select_fields FROM donors WHERE id = ? LIMIT 1");
            $refresh_stmt->bind_param('i', $donor['id']);
            $refresh_stmt->execute();
            $fresh_donor = $refresh_stmt->get_result()->fetch_assoc();
            $refresh_stmt->close();
            
            if ($fresh_donor) {
                $_SESSION['donor'] = $fresh_donor;
                $donor = $fresh_donor;
            }
        } catch (Exception $e) {
            error_log("Failed to refresh donor session: " . $e->getMessage());
        }
    }
    
    $page_title = 'Update Pledge Amount';
    $current_donor = $donor;

    // Load donation packages for amount selection
    $currency = isset($settings) && is_array($settings) ? ($settings['currency_code'] ?? 'GBP') : 'GBP';
    $pkgRows = [];
    if (isset($db_connection_ok) && $db_connection_ok && isset($db) && $db instanceof mysqli) {
        try {
            $pkg_table_exists = $db->query("SHOW TABLES LIKE 'donation_packages'")->num_rows > 0;
            if ($pkg_table_exists) {
                $pkgRows = $db->query("SELECT id, label, sqm_meters, price FROM donation_packages WHERE active=1 ORDER BY sort_order, id")->fetch_all(MYSQLI_ASSOC);
            }
        } catch(Exception $e) {
            error_log("Donor update-pledge: Error loading packages - " . $e->getMessage());
            // Silent fail
        }
    }
} catch (Throwable $e) {
    error_log("Donor update-pledge: Fatal error during initialization - " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    error_log("Donor update-pledge: Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    die("An error occurred while loading the page: " . htmlspecialchars($e->getMessage()) . "<br>File: " . $e->getFile() . "<br>Line: " . $e->getLine() . "<br><pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>");
}

$pkgByLabel = [];
foreach ($pkgRows as $r) { $pkgByLabel[$r['label']] = $r; }
$pkgOne     = $pkgByLabel['1 m²']   ?? null;
$pkgHalf    = $pkgByLabel['1/2 m²'] ?? null;
$pkgQuarter = $pkgByLabel['1/4 m²'] ?? null;
$pkgCustom  = $pkgByLabel['Custom'] ?? null;

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("Donor pledge update: POST request received");
    error_log("Donor pledge update: POST data keys: " . implode(', ', array_keys($_POST)));
    
    try {
        verify_csrf();
        error_log("Donor pledge update: CSRF verified");
    } catch (Exception $csrfError) {
        error_log("Donor pledge update: CSRF verification failed - " . $csrfError->getMessage());
        $error = 'Security verification failed. Please refresh and try again.';
    }

    if (empty($error)) {
        try {
            // Collect form inputs
            error_log("Donor pledge update: Collecting form inputs...");
            $notes = trim((string)($_POST['notes'] ?? '')); // Optional notes
            $sqm_unit = (string)($_POST['pack'] ?? ''); // '1', '0.5', '0.25', 'custom'
            $custom_amount = (float)($_POST['custom_amount'] ?? 0);
            $client_uuid = trim((string)($_POST['client_uuid'] ?? ''));
            error_log("Donor pledge update: Form inputs - sqm_unit=$sqm_unit, custom_amount=$custom_amount, client_uuid=" . substr($client_uuid, 0, 20));
            
            if ($client_uuid === '') {
                try { 
                    $client_uuid = bin2hex(random_bytes(16)); 
                    error_log("Donor pledge update: Generated new client_uuid");
                } catch (Throwable $e) { 
                    $client_uuid = uniqid('uuid_', true); 
                    error_log("Donor pledge update: Generated fallback client_uuid");
                }
            }

            // Validation
            if (empty($client_uuid)) {
                $error = 'A unique submission ID is required. Please refresh and try again.';
                error_log("Donor pledge update: ERROR - client_uuid is empty");
            }

            // Calculate donation amount based on selection
            error_log("Donor pledge update: Calculating amount...");
            $amount = 0.0;
            $selectedPackage = null;
            if ($sqm_unit === '1') { 
                $selectedPackage = $pkgOne; 
                error_log("Donor pledge update: Selected 1 m² package");
            }
            elseif ($sqm_unit === '0.5') { 
                $selectedPackage = $pkgHalf; 
                error_log("Donor pledge update: Selected 1/2 m² package");
            }
            elseif ($sqm_unit === '0.25') { 
                $selectedPackage = $pkgQuarter; 
                error_log("Donor pledge update: Selected 1/4 m² package");
            }
            elseif ($sqm_unit === 'custom') { 
                $selectedPackage = $pkgCustom; 
                error_log("Donor pledge update: Selected custom package");
            }
            else { 
                $selectedPackage = null; 
                error_log("Donor pledge update: No package selected, sqm_unit=$sqm_unit");
            }

            if ($selectedPackage) {
                if ($sqm_unit === 'custom') {
                    $amount = max(0, $custom_amount);
                    error_log("Donor pledge update: Custom amount = $amount");
                } else {
                    $amount = (float)$selectedPackage['price'];
                    error_log("Donor pledge update: Package amount = $amount (from package ID: " . ($selectedPackage['id'] ?? 'N/A') . ")");
                }
            } else {
                $error = 'Please select a valid donation package.';
                error_log("Donor pledge update: ERROR - No valid package selected. Available packages: pkgOne=" . ($pkgOne ? 'YES' : 'NO') . ", pkgHalf=" . ($pkgHalf ? 'YES' : 'NO') . ", pkgQuarter=" . ($pkgQuarter ? 'YES' : 'NO') . ", pkgCustom=" . ($pkgCustom ? 'YES' : 'NO'));
            }

            if ($amount <= 0 && !$error) {
                $error = 'Please select a valid amount greater than zero.';
                error_log("Donor pledge update: ERROR - Amount is $amount (must be > 0)");
            }
        } catch (Throwable $validationError) {
            error_log("Donor pledge update: ERROR during validation - " . $validationError->getMessage() . " in " . $validationError->getFile() . ":" . $validationError->getLine());
            $error = 'Validation error: ' . htmlspecialchars($validationError->getMessage());
        }
    }

    // Process the database transaction
    if (empty($error)) {
        // Debug: Check database connection
        error_log("Donor pledge update: Checking database connection...");
        if (!$db_connection_ok) {
            $error = 'Database connection is not available. Please contact support.';
            error_log("Donor pledge update: ERROR - db_connection_ok is false");
        } elseif (!isset($db) || !($db instanceof mysqli)) {
            $error = 'Database object is not available. Please contact support.';
            error_log("Donor pledge update: ERROR - db is not set or not mysqli instance. isset(db)=" . (isset($db) ? 'YES' : 'NO') . ", instanceof=" . (isset($db) && $db instanceof mysqli ? 'YES' : 'NO'));
        } else {
            try {
                // Debug: Log step
                error_log("Donor pledge update: Starting transaction. Donor ID: " . ($donor['id'] ?? 'N/A') . ", Amount: $amount, Package: " . ($selectedPackage['label'] ?? 'N/A'));
                error_log("Donor pledge update: Database connection OK, db_connection_ok=" . ($db_connection_ok ? 'true' : 'false'));
                
                $db->autocommit(false);
                error_log("Donor pledge update: autocommit set to false");
                
                // Donor data from session
                $donorName = $donor['name'] ?? 'Anonymous';
                $donorPhone = $donor['phone'] ?? '';
                $donorEmail = null;
                
                if (empty($donorPhone)) {
                    throw new Exception("Donor phone number is missing from session.");
                }
                if (empty($donorName)) {
                    $donorName = 'Anonymous';
                }
                
                // Normalize phone number
                $normalized_phone = preg_replace('/[^0-9]/', '', $donorPhone);
                if (substr($normalized_phone, 0, 2) === '44' && strlen($normalized_phone) === 12) {
                    $normalized_phone = '0' . substr($normalized_phone, 2);
                }

                // Normalize notes (tombola code if provided, otherwise empty)
                $notesDigits = preg_replace('/\D+/', '', $notes);
                $final_notes = !empty($notesDigits) ? $notesDigits : '';

                // Check for duplicate UUID
                error_log("Donor pledge update: Checking for duplicate UUID: $client_uuid");
                $stmt = $db->prepare("SELECT id FROM pledges WHERE client_uuid = ?");
                if (!$stmt) {
                    throw new Exception("Failed to prepare duplicate check query: " . $db->error);
                }
                $stmt->bind_param("s", $client_uuid);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to execute duplicate check: " . $stmt->error);
                }
                $result = $stmt->get_result();
                if ($result->fetch_assoc()) {
                    $stmt->close();
                    throw new Exception("Duplicate submission detected. Please do not click submit twice.");
                }
                $stmt->close();
                error_log("Donor pledge update: No duplicate found, proceeding...");

                // Check if donor_email column exists in pledges table
                error_log("Donor pledge update: Checking for donor_email column...");
                $has_donor_email_column = false;
                try {
                    $check_col = $db->query("SHOW COLUMNS FROM pledges LIKE 'donor_email'");
                    if ($check_col) {
                        $has_donor_email_column = $check_col->num_rows > 0;
                    }
                } catch (Exception $e) {
                    error_log("Donor pledge update: donor_email column check failed: " . $e->getMessage());
                    // Column doesn't exist, that's fine
                }
                
                // Check if donor_id column exists in pledges table
                error_log("Donor pledge update: Checking for donor_id column...");
                $has_donor_id_column = false;
                try {
                    $check_col = $db->query("SHOW COLUMNS FROM pledges LIKE 'donor_id'");
                    if ($check_col) {
                        $has_donor_id_column = $check_col->num_rows > 0;
                    }
                } catch (Exception $e) {
                    error_log("Donor pledge update: donor_id column check failed: " . $e->getMessage());
                    // Column doesn't exist, that's fine
                }
                
                error_log("Donor pledge update: Column check results - donor_id: " . ($has_donor_id_column ? 'YES' : 'NO') . ", donor_email: " . ($has_donor_email_column ? 'YES' : 'NO'));
            
                // Determine created_by_user_id: Use System Admin (ID 0) if exists, otherwise NULL
                error_log("Donor pledge update: Checking for System Admin user (ID 0)...");
                $created_by_user_id = null;
                try {
                    $check_system_admin = $db->prepare("SELECT id FROM users WHERE id = 0 LIMIT 1");
                    if ($check_system_admin && $check_system_admin->execute()) {
                        $sys_admin_result = $check_system_admin->get_result();
                        if ($sys_admin_result->fetch_assoc()) {
                            $created_by_user_id = 0;
                            error_log("Donor pledge update: System Admin (ID 0) found, using it as created_by_user_id");
                        } else {
                            error_log("Donor pledge update: System Admin (ID 0) not found, using NULL for created_by_user_id");
                        }
                        $check_system_admin->close();
                    }
                } catch (Exception $e) {
                    error_log("Donor pledge update: Error checking System Admin: " . $e->getMessage() . " - Using NULL");
                    // Use NULL if check fails
                }
            
                // Create new pending pledge for the additional amount
                $status = 'pending';
                $packageId = (int)($selectedPackage['id'] ?? 0);
                $packageIdNullable = $packageId > 0 ? $packageId : null;
                $donorId = (int)($donor['id'] ?? 0);
                $donorIdNullable = $donorId > 0 ? $donorId : null;
                
                // Get donor email if available
                $donorEmail = null;
                if ($has_donor_email_column && isset($donor['email']) && !empty($donor['email'])) {
                    $donorEmail = trim($donor['email']);
                }
                
                // Build INSERT query dynamically based on column existence
                // Handle NULL for created_by_user_id by conditionally including it in the query
                $includeCreatedBy = ($created_by_user_id !== null);
                
                if ($has_donor_id_column && $has_donor_email_column) {
                    if ($includeCreatedBy) {
                        $stmt = $db->prepare("
                            INSERT INTO pledges (
                              donor_id, donor_name, donor_phone, donor_email, source, anonymous,
                              amount, type, status, notes, client_uuid, created_by_user_id, package_id
                            ) VALUES (?, ?, ?, ?, 'self', 0, ?, 'pledge', ?, ?, ?, ?, ?)
                        ");
                        if (!$stmt) {
                            throw new Exception("Failed to prepare INSERT query: " . $db->error);
                        }
                        $stmt->bind_param(
                            'isssdsssii',
                            $donorIdNullable, $donorName, $donorPhone, $donorEmail,
                            $amount, $status, $final_notes, $client_uuid, $created_by_user_id, $packageIdNullable
                        );
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO pledges (
                              donor_id, donor_name, donor_phone, donor_email, source, anonymous,
                              amount, type, status, notes, client_uuid, package_id
                            ) VALUES (?, ?, ?, ?, 'self', 0, ?, 'pledge', ?, ?, ?, ?)
                        ");
                        if (!$stmt) {
                            throw new Exception("Failed to prepare INSERT query: " . $db->error);
                        }
                        $stmt->bind_param(
                            'isssdsssi',
                            $donorIdNullable, $donorName, $donorPhone, $donorEmail,
                            $amount, $status, $final_notes, $client_uuid, $packageIdNullable
                        );
                    }
                } elseif ($has_donor_id_column && !$has_donor_email_column) {
                    error_log("Donor pledge update: Using query variant: has_donor_id only");
                    if ($includeCreatedBy) {
                        $stmt = $db->prepare("
                            INSERT INTO pledges (
                              donor_id, donor_name, donor_phone, source, anonymous,
                              amount, type, status, notes, client_uuid, created_by_user_id, package_id
                            ) VALUES (?, ?, ?, 'self', 0, ?, 'pledge', ?, ?, ?, ?, ?)
                        ");
                        if (!$stmt) {
                            throw new Exception("Failed to prepare INSERT query: " . $db->error);
                        }
                        $stmt->bind_param(
                            'issdsssii',
                            $donorIdNullable, $donorName, $donorPhone,
                            $amount, $status, $final_notes, $client_uuid, $created_by_user_id, $packageIdNullable
                        );
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO pledges (
                              donor_id, donor_name, donor_phone, source, anonymous,
                              amount, type, status, notes, client_uuid, package_id
                            ) VALUES (?, ?, ?, 'self', 0, ?, 'pledge', ?, ?, ?, ?)
                        ");
                        if (!$stmt) {
                            throw new Exception("Failed to prepare INSERT query: " . $db->error);
                        }
                        $stmt->bind_param(
                            'issdsssi',
                            $donorIdNullable, $donorName, $donorPhone,
                            $amount, $status, $final_notes, $client_uuid, $packageIdNullable
                        );
                    }
                } elseif (!$has_donor_id_column && $has_donor_email_column) {
                    error_log("Donor pledge update: Using query variant: has_donor_email only");
                    if ($includeCreatedBy) {
                        $stmt = $db->prepare("
                            INSERT INTO pledges (
                              donor_name, donor_phone, donor_email, source, anonymous,
                              amount, type, status, notes, client_uuid, created_by_user_id, package_id
                            ) VALUES (?, ?, ?, 'self', 0, ?, 'pledge', ?, ?, ?, ?, ?)
                        ");
                        if (!$stmt) {
                            throw new Exception("Failed to prepare INSERT query: " . $db->error);
                        }
                        $stmt->bind_param(
                            'sssdsssii',
                            $donorName, $donorPhone, $donorEmail,
                            $amount, $status, $final_notes, $client_uuid, $created_by_user_id, $packageIdNullable
                        );
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO pledges (
                              donor_name, donor_phone, donor_email, source, anonymous,
                              amount, type, status, notes, client_uuid, package_id
                            ) VALUES (?, ?, ?, 'self', 0, ?, 'pledge', ?, ?, ?, ?)
                        ");
                        if (!$stmt) {
                            throw new Exception("Failed to prepare INSERT query: " . $db->error);
                        }
                        $stmt->bind_param(
                            'sssdsssi',
                            $donorName, $donorPhone, $donorEmail,
                            $amount, $status, $final_notes, $client_uuid, $packageIdNullable
                        );
                    }
                } else {
                    // Neither column exists (match registrar pattern)
                    error_log("Donor pledge update: Using query variant: neither donor_id nor donor_email");
                    if ($includeCreatedBy) {
                        $stmt = $db->prepare("
                            INSERT INTO pledges (
                              donor_name, donor_phone, source, anonymous,
                              amount, type, status, notes, client_uuid, created_by_user_id, package_id
                            ) VALUES (?, ?, 'self', 0, ?, 'pledge', ?, ?, ?, ?, ?)
                        ");
                        if (!$stmt) {
                            throw new Exception("Failed to prepare INSERT query: " . $db->error);
                        }
                        $stmt->bind_param(
                            'ssdsssii',
                            $donorName, $donorPhone,
                            $amount, $status, $final_notes, $client_uuid, $created_by_user_id, $packageIdNullable
                        );
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO pledges (
                              donor_name, donor_phone, source, anonymous,
                              amount, type, status, notes, client_uuid, package_id
                            ) VALUES (?, ?, 'self', 0, ?, 'pledge', ?, ?, ?, ?)
                        ");
                        if (!$stmt) {
                            throw new Exception("Failed to prepare INSERT query: " . $db->error);
                        }
                        $stmt->bind_param(
                            'ssdsssi',
                            $donorName, $donorPhone,
                            $amount, $status, $final_notes, $client_uuid, $packageIdNullable
                        );
                    }
                }
            
                error_log("Donor pledge update: Executing INSERT statement...");
                error_log("Donor pledge update: bind_param types: " . (isset($stmt) ? 'prepared' : 'NOT PREPARED'));
                error_log("Donor pledge update: donorIdNullable=" . var_export($donorIdNullable, true) . ", donorName=" . substr($donorName, 0, 20) . ", amount=$amount");
                
                if (!$stmt->execute()) {
                    $error_msg = $stmt->error ?: $db->error ?: 'Unknown SQL error';
                    error_log("Donor pledge update: INSERT FAILED - SQL Error: " . $error_msg);
                    error_log("Donor pledge update: MySQL Error Code: " . $stmt->errno);
                    error_log("Donor pledge update: Database Error: " . $db->error);
                    $stmt->close();
                    throw new Exception('Failed to create pledge request: ' . $error_msg . ' (Error Code: ' . $stmt->errno . ')');
                }
                if ($stmt->affected_rows === 0) { 
                    error_log("Donor pledge update: INSERT succeeded but no rows affected!");
                    error_log("Donor pledge update: affected_rows=" . $stmt->affected_rows);
                    $stmt->close();
                    throw new Exception('Failed to create pledge request (no rows affected).'); 
                }
                $entityId = $db->insert_id;
                error_log("Donor pledge update: INSERT successful, new pledge ID: $entityId, affected_rows=" . $stmt->affected_rows);
                $stmt->close();

                // Create allocation batch record for tracking
                // Check if grid_allocation_batches table exists first
                try {
                    $tableExists = $db->query("SHOW TABLES LIKE 'grid_allocation_batches'")->num_rows > 0;
                    if ($tableExists) {
                        $batchTracker = new GridAllocationBatchTracker($db);
                        $donorId = (int)($donor['id'] ?? 0);
                        $donorIdNullable = $donorId > 0 ? $donorId : null;
                        
                        // Find original approved pledge for this donor
                        $originalPledgeId = null;
                        $originalAmount = 0.00;
                        if ($donorIdNullable) {
                            $findOriginal = $db->prepare("
                                SELECT id, amount 
                                FROM pledges 
                                WHERE donor_id = ? AND status = 'approved' AND type = 'pledge' 
                                ORDER BY approved_at DESC, id DESC 
                                LIMIT 1
                            ");
                            $findOriginal->bind_param('i', $donorIdNullable);
                            $findOriginal->execute();
                            $originalPledge = $findOriginal->get_result()->fetch_assoc();
                            $findOriginal->close();
                            if ($originalPledge) {
                                $originalPledgeId = (int)$originalPledge['id'];
                                $originalAmount = (float)$originalPledge['amount'];
                            }
                        }
                        
                        // Create batch record
                        $batchData = [
                            'batch_type' => $originalPledgeId ? 'pledge_update' : 'new_pledge',
                            'request_type' => 'donor_portal',
                            'original_pledge_id' => $originalPledgeId,
                            'new_pledge_id' => $entityId,
                            'donor_id' => $donorIdNullable,
                            'donor_name' => $donorName,
                            'donor_phone' => $normalized_phone,
                            'original_amount' => $originalAmount,
                            'additional_amount' => $amount,
                            'total_amount' => $originalAmount + $amount,
                            'requested_by_donor_id' => $donorIdNullable,
                            'request_source' => 'self',
                            'package_id' => $packageIdNullable,
                            'metadata' => [
                                'client_uuid' => $client_uuid,
                                'notes' => $final_notes
                            ]
                        ];
                        $batchId = $batchTracker->createBatch($batchData);
                        if ($batchId) {
                            error_log("Donor pledge update: Created allocation batch #{$batchId}");
                        } else {
                            error_log("Donor pledge update: WARNING - Failed to create allocation batch");
                        }
                    } else {
                        error_log("Donor pledge update: WARNING - grid_allocation_batches table does not exist, skipping batch creation");
                    }
                } catch (Exception $batchError) {
                    error_log("Donor pledge update: Error creating batch - " . $batchError->getMessage());
                    // Don't fail the whole transaction if batch creation fails
                }

                // Audit log
                error_log("Donor pledge update: Creating audit log for entity ID: $entityId");
                try {
                    $afterJson = json_encode([
                        'amount'=>$amount,
                        'type'=>'pledge',
                        'donor'=>$donorName,
                        'status'=>'pending',
                        'source'=>'donor_portal',
                        'current_total_pledged'=>$donor['total_pledged'] ?? 0
                    ]);
                    error_log("Donor pledge update: Audit JSON prepared: " . substr($afterJson, 0, 100));
                    
                    $log = $db->prepare("INSERT INTO audit_logs(user_id, entity_type, entity_id, action, after_json, source) VALUES(0, 'pledge', ?, 'create_pending', ?, 'donor_portal')");
                    if (!$log) {
                        error_log("Donor pledge update: WARNING - Failed to prepare audit log query: " . $db->error);
                        throw new Exception("Failed to prepare audit log query: " . $db->error);
                    }
                    $log->bind_param('is', $entityId, $afterJson);
                    if (!$log->execute()) {
                        error_log("Donor pledge update: WARNING - Audit log execution failed: " . $log->error . " (Error code: " . $log->errno . ")");
                        // Don't fail the whole transaction if audit log fails
                    } else {
                        error_log("Donor pledge update: Audit log created successfully");
                    }
                    $log->close();
                } catch (Exception $auditError) {
                    error_log("Donor pledge update: ERROR creating audit log - " . $auditError->getMessage());
                    // Don't fail the whole transaction if audit log fails
                }

                error_log("Donor pledge update: Committing transaction...");
                if (!$db->commit()) {
                    error_log("Donor pledge update: ERROR - Commit failed: " . $db->error);
                    throw new Exception("Transaction commit failed: " . $db->error);
                }
                $db->autocommit(true);
                error_log("Donor pledge update: Transaction committed successfully");
                
                $_SESSION['success_message'] = "Your pledge increase request for {$currency} " . number_format($amount, 2) . " has been submitted for approval!";
                error_log("Donor pledge update: Success! Redirecting...");
                header('Location: update-pledge.php');
                exit;
            } catch (mysqli_sql_exception $e) {
                error_log("Donor pledge update: CATCH mysqli_sql_exception");
                error_log("Donor pledge update: Exception message: " . $e->getMessage());
                error_log("Donor pledge update: Exception code: " . $e->getCode());
                error_log("Donor pledge update: Exception file: " . $e->getFile());
                error_log("Donor pledge update: Exception line: " . $e->getLine());
                error_log("Donor pledge update: Exception trace: " . $e->getTraceAsString());
                
                if (isset($db) && $db instanceof mysqli) {
                    error_log("Donor pledge update: Rolling back transaction...");
                    $db->rollback();
                    $db->autocommit(true);
                    error_log("Donor pledge update: Database error code: " . $db->errno);
                    error_log("Donor pledge update: Database error message: " . $db->error);
                }
                
                $error_msg = $e->getMessage() . " | SQL Error: " . (isset($db) && $db instanceof mysqli ? ($db->error ?? 'N/A') : 'DB not available') . " | Line: " . $e->getLine();
                error_log("Donor pledge update SQL error: " . $error_msg);
                
                // Show detailed error - always show full details for debugging
                $dbError = (isset($db) && $db instanceof mysqli) ? $db->error : 'N/A';
                $dbErrno = (isset($db) && $db instanceof mysqli) ? $db->errno : 'N/A';
                $error = 'Database error: ' . htmlspecialchars($e->getMessage()) . 
                    '<br><strong>SQL Error:</strong> ' . htmlspecialchars($dbError) . 
                    '<br><strong>Error Code:</strong> ' . $dbErrno . 
                    '<br><strong>File:</strong> ' . $e->getFile() . 
                    '<br><strong>Line:</strong> ' . $e->getLine() .
                    '<br><strong>Trace:</strong> <pre style="background:#f5f5f5;padding:10px;overflow:auto;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            } catch (Exception $e) {
                error_log("Donor pledge update: CATCH Exception");
                error_log("Donor pledge update: Exception message: " . $e->getMessage());
                error_log("Donor pledge update: Exception code: " . $e->getCode());
                error_log("Donor pledge update: Exception file: " . $e->getFile());
                error_log("Donor pledge update: Exception line: " . $e->getLine());
                error_log("Donor pledge update: Exception trace: " . $e->getTraceAsString());
                
                if (isset($db) && $db instanceof mysqli) {
                    error_log("Donor pledge update: Rolling back transaction...");
                    $db->rollback();
                    $db->autocommit(true);
                    if ($db->error) {
                        error_log("Donor pledge update: Database error: " . $db->error);
                    }
                }
                
                $error_msg = $e->getMessage() . " on line " . $e->getLine() . " | File: " . $e->getFile();
                error_log("Donor pledge update error: " . $error_msg);
                
                // Show detailed error - always show full details for debugging
                $error = 'Error saving request: ' . htmlspecialchars($e->getMessage()) . 
                    '<br><strong>File:</strong> ' . $e->getFile() . 
                    '<br><strong>Line:</strong> ' . $e->getLine() . 
                    '<br><strong>Code:</strong> ' . $e->getCode() .
                    '<br><strong>Trace:</strong> <pre style="background:#f5f5f5;padding:10px;overflow:auto;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            } catch (Throwable $e) {
                error_log("Donor pledge update: CATCH Throwable (fatal error)");
                error_log("Donor pledge update: Throwable message: " . $e->getMessage());
                error_log("Donor pledge update: Throwable file: " . $e->getFile());
                error_log("Donor pledge update: Throwable line: " . $e->getLine());
                error_log("Donor pledge update: Throwable trace: " . $e->getTraceAsString());
                
                if (isset($db) && $db instanceof mysqli) {
                    $db->rollback();
                    $db->autocommit(true);
                }
                
                // Show detailed error - always show full details for debugging
                $error = 'Fatal error: ' . htmlspecialchars($e->getMessage()) . 
                    '<br><strong>File:</strong> ' . $e->getFile() . 
                    '<br><strong>Line:</strong> ' . $e->getLine() . 
                    '<br><strong>Trace:</strong> <pre style="background:#f5f5f5;padding:10px;overflow:auto;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            }
        }
    }
}

// Check for success message from redirect
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($donor['preferred_language'] ?? 'en'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($page_title); ?> - Donor Portal</title>
    <link rel="icon" type="image/svg+xml" href="../assets/favicon.svg">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/theme.css?v=<?php echo @filemtime(__DIR__ . '/../assets/theme.css'); ?>">
    <link rel="stylesheet" href="assets/donor.css?v=<?php echo @filemtime(__DIR__ . '/assets/donor.css'); ?>">
</head>
<body>
<div class="app-wrapper">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="app-content">
        <?php include 'includes/topbar.php'; ?>
        
        <main class="main-content">
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-handshake me-2"></i>Update Pledge Amount
                </h1>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <div style="margin-top: 10px;">
                    <?php echo $error; // Error already contains safe HTML ?>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="row g-4">
                <!-- Current Pledge Info -->
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title">
                                <i class="fas fa-info-circle text-primary"></i>Current Pledge
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label text-muted">Total Pledged</label>
                                <p class="mb-0"><strong class="fs-4">£<?php echo number_format($donor['total_pledged'] ?? 0, 2); ?></strong></p>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted">Total Paid</label>
                                <p class="mb-0"><strong>£<?php echo number_format($donor['total_paid'] ?? 0, 2); ?></strong></p>
                            </div>
                            <div class="mb-0">
                                <label class="form-label text-muted">Remaining Balance</label>
                                <p class="mb-0"><strong>£<?php echo number_format($donor['balance'] ?? 0, 2); ?></strong></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Update Form -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title">
                                <i class="fas fa-plus-circle text-primary"></i>Request Pledge Increase
                            </h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-4">
                                <i class="fas fa-info-circle me-2"></i>
                                Select an additional amount to add to your current pledge. Your request will be reviewed by an administrator before approval.
                            </p>

                            <form method="POST" class="needs-validation" novalidate>
                                <?php echo csrf_input(); ?>
                                <input type="hidden" name="client_uuid" value="">
                                
                                <!-- Amount Selection -->
                                <div class="mb-4">
                                    <label class="form-label fw-bold">
                                        <i class="fas fa-pound-sign me-2"></i>Select Additional Amount <span class="text-danger">*</span>
                                    </label>
                                    
                                    <div class="quick-amounts" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; margin-bottom: 1.5rem;">
                                        <?php if ($pkgOne): ?>
                                        <label class="quick-amount-btn" data-pack="1">
                                            <input type="radio" name="pack" value="1" class="d-none" required>
                                            <span class="quick-amount-value"><?php echo $currency; ?> <?php echo number_format((float)$pkgOne['price'], 0); ?></span>
                                            <span class="quick-amount-label">1 Square Meter</span>
                                            <i class="fas fa-check-circle checkmark"></i>
                                        </label>
                                        <?php endif; ?>
                                        
                                        <?php if ($pkgHalf): ?>
                                        <label class="quick-amount-btn" data-pack="0.5">
                                            <input type="radio" name="pack" value="0.5" class="d-none" required>
                                            <span class="quick-amount-value"><?php echo $currency; ?> <?php echo number_format((float)$pkgHalf['price'], 0); ?></span>
                                            <span class="quick-amount-label">½ Square Meter</span>
                                            <i class="fas fa-check-circle checkmark"></i>
                                        </label>
                                        <?php endif; ?>
                                        
                                        <?php if ($pkgQuarter): ?>
                                        <label class="quick-amount-btn" data-pack="0.25">
                                            <input type="radio" name="pack" value="0.25" class="d-none" required>
                                            <span class="quick-amount-value"><?php echo $currency; ?> <?php echo number_format((float)$pkgQuarter['price'], 0); ?></span>
                                            <span class="quick-amount-label">¼ Square Meter</span>
                                            <i class="fas fa-check-circle checkmark"></i>
                                        </label>
                                        <?php endif; ?>
                                        
                                        <label class="quick-amount-btn" data-pack="custom">
                                            <input type="radio" name="pack" value="custom" class="d-none" required>
                                            <span class="quick-amount-value">Custom</span>
                                            <span class="quick-amount-label">Enter Amount</span>
                                            <i class="fas fa-check-circle checkmark"></i>
                                        </label>
                                    </div>
                                    
                                    <style>
                                    .quick-amount-btn {
                                        padding: 1rem;
                                        border: 2px solid #dee2e6;
                                        border-radius: 12px;
                                        background: white;
                                        cursor: pointer;
                                        transition: all 0.3s ease;
                                        text-align: center;
                                        position: relative;
                                        color: #333;
                                    }
                                    .quick-amount-btn:hover {
                                        border-color: #0a6286;
                                        background: #f0f8ff;
                                        transform: translateY(-2px);
                                        box-shadow: 0 2px 8px rgba(10, 98, 134, 0.15);
                                    }
                                    .quick-amount-btn.active {
                                        border-color: #0a6286 !important;
                                        border-width: 3px !important;
                                        background: linear-gradient(135deg, #0a6286 0%, #0d7ba8 100%) !important;
                                        color: white !important;
                                        box-shadow: 0 6px 20px rgba(10, 98, 134, 0.4) !important;
                                        transform: translateY(-3px) scale(1.02);
                                        animation: selectedPulse 0.5s ease-out;
                                    }
                                    @keyframes selectedPulse {
                                        0% {
                                            transform: translateY(-3px) scale(1);
                                            box-shadow: 0 4px 12px rgba(10, 98, 134, 0.3);
                                        }
                                        50% {
                                            transform: translateY(-3px) scale(1.05);
                                            box-shadow: 0 8px 24px rgba(10, 98, 134, 0.5);
                                        }
                                        100% {
                                            transform: translateY(-3px) scale(1.02);
                                            box-shadow: 0 6px 20px rgba(10, 98, 134, 0.4);
                                        }
                                    }
                                    .quick-amount-btn.active .quick-amount-value {
                                        color: white !important;
                                        font-size: 1.35rem;
                                        font-weight: 800;
                                    }
                                    .quick-amount-btn.active .quick-amount-label {
                                        color: rgba(255, 255, 255, 0.95) !important;
                                        font-weight: 600;
                                    }
                                    .quick-amount-btn.active .checkmark {
                                        opacity: 1 !important;
                                        transform: scale(1.2) !important;
                                        color: white !important;
                                        animation: checkmarkPop 0.4s ease-out 0.1s both;
                                    }
                                    @keyframes checkmarkPop {
                                        0% {
                                            transform: scale(0.5);
                                            opacity: 0;
                                        }
                                        50% {
                                            transform: scale(1.4);
                                        }
                                        100% {
                                            transform: scale(1.2);
                                            opacity: 1;
                                        }
                                    }
                                    .quick-amount-value {
                                        font-size: 1.25rem;
                                        font-weight: 700;
                                        display: block;
                                        margin-bottom: 0.25rem;
                                        transition: all 0.3s ease;
                                    }
                                    .quick-amount-label {
                                        font-size: 0.75rem;
                                        opacity: 0.8;
                                        transition: all 0.3s ease;
                                    }
                                    .checkmark {
                                        position: absolute;
                                        top: 8px;
                                        right: 8px;
                                        font-size: 1.2rem;
                                        color: #0a6286;
                                        opacity: 0;
                                        transform: scale(0.5);
                                        transition: all 0.3s ease;
                                    }
                                    @media (min-width: 768px) {
                                        .quick-amounts {
                                            grid-template-columns: repeat(4, 1fr) !important;
                                        }
                                    }
                                    </style>
                                    
                                    <div class="mb-3 d-none mt-3" id="customAmountDiv">
                                        <label for="custom_amount" class="form-label">Custom Amount</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><?php echo $currency; ?></span>
                                            <input type="number" class="form-control" id="custom_amount" name="custom_amount" 
                                                   min="1" step="0.01" placeholder="0.00">
                                        </div>
                                    </div>
                                </div>

                                <!-- Optional Notes -->
                                <div class="mb-4">
                                    <label for="notes" class="form-label">
                                        <i class="fas fa-sticky-note me-2"></i>Optional Notes
                                    </label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3" 
                                              placeholder="Any additional information about your pledge increase..."></textarea>
                                    <div class="form-text">This information will be visible to administrators when reviewing your request.</div>
                                </div>

                                <!-- Submit Button -->
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-paper-plane me-2"></i>Submit Request for Approval
                                    </button>
                                    <a href="<?php echo htmlspecialchars(url_for('donor/index.php')); ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/donor.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Generate UUID for form
    const uuidv4 = () => {
        if (self.crypto && typeof self.crypto.randomUUID === 'function') {
            try { return self.crypto.randomUUID(); } catch (e) {}
        }
        const bytes = new Uint8Array(16);
        if (self.crypto && self.crypto.getRandomValues) {
            self.crypto.getRandomValues(bytes);
        } else {
            for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
        }
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const toHex = (n) => n.toString(16).padStart(2, '0');
        const b = Array.from(bytes, toHex);
        return `${b[0]}${b[1]}${b[2]}${b[3]}-${b[4]}${b[5]}-${b[6]}${b[7]}-${b[8]}${b[9]}-${b[10]}${b[11]}${b[12]}${b[13]}${b[14]}${b[15]}`;
    };
    
    const uuidInput = document.querySelector('input[name="client_uuid"]');
    if (uuidInput) {
        uuidInput.value = uuidv4();
    }

    // Quick amount selection
    document.querySelectorAll('.quick-amount-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.quick-amount-btn').forEach(b => {
                b.classList.remove('active');
            });
            this.classList.add('active');
            
            const pack = this.dataset.pack;
            const radio = this.querySelector('input[type="radio"]');
            if (radio) radio.checked = true;
            
            if (pack === 'custom') {
                document.getElementById('customAmountDiv').classList.remove('d-none');
                document.getElementById('custom_amount').focus();
                document.getElementById('custom_amount').required = true;
            } else {
                document.getElementById('customAmountDiv').classList.add('d-none');
                document.getElementById('custom_amount').required = false;
                document.getElementById('custom_amount').value = '';
            }
        });
    });

    // Form validation
    const form = document.querySelector('.needs-validation');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    }

    // Auto-dismiss success message
    const successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(function() {
            const bsAlert = new bootstrap.Alert(successAlert);
            bsAlert.close();
        }, 5000);
    }
});
</script>
</body>
</html>

