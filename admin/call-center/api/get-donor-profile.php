<?php
/**
 * API: Get Full Donor Profile
 * 
 * Returns comprehensive donor data for the call widget modal
 * Same data as view-donor.php but as JSON
 */

declare(strict_types=1);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../../shared/auth.php';
    require_once __DIR__ . '/../../../config/db.php';
    
    require_login();
    
    $db = db();
    $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
    
    if ($donor_id <= 0) {
        throw new Exception('Invalid donor ID');
    }
    
    // 1. Get Donor Details (same as view-donor.php)
    $donor_query = "
        SELECT 
            d.*,
            u.name as registrar_name,
            c.name as church_name,
            c.city as church_city
        FROM donors d
        LEFT JOIN users u ON d.registered_by_user_id = u.id
        LEFT JOIN churches c ON d.church_id = c.id
        WHERE d.id = ?
    ";
    $stmt = $db->prepare($donor_query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $donor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$donor) {
        throw new Exception('Donor not found');
    }
    
    // 2. Get Pledges
    $pledges = [];
    $pledge_query = "
        SELECT p.*, u.name as registrar_name 
        FROM pledges p 
        LEFT JOIN users u ON p.created_by_user_id = u.id
        WHERE p.donor_id = ? 
        ORDER BY p.created_at DESC
    ";
    $stmt = $db->prepare($pledge_query);
    $stmt->bind_param('i', $donor_id);
    $stmt->execute();
    $pledge_result = $stmt->get_result();
    while ($p = $pledge_result->fetch_assoc()) {
        $pledges[] = $p;
    }
    $stmt->close();
    
    // Extract reference number from pledge notes
    $reference_number = '';
    if (!empty($pledges) && !empty($pledges[0]['notes'])) {
        if (preg_match('/\b(\d{4})\b/', $pledges[0]['notes'], $matches)) {
            $reference_number = $matches[1];
        }
    }
    if (empty($reference_number)) {
        $reference_number = str_pad((string)$donor['id'], 4, '0', STR_PAD_LEFT);
    }
    
    // 3. Get Payments (both instant and pledge payments)
    $payments = [];
    $has_pledge_payments = $db->query("SHOW TABLES LIKE 'pledge_payments'")->num_rows > 0;
    
    // Check payments table columns
    $payment_columns = [];
    $col_query = $db->query("SHOW COLUMNS FROM payments");
    while ($col = $col_query->fetch_assoc()) {
        $payment_columns[] = $col['Field'];
    }
    
    $approver_col = in_array('approved_by_user_id', $payment_columns) ? 'approved_by_user_id' : 
                   (in_array('received_by_user_id', $payment_columns) ? 'received_by_user_id' : 'id');
    $date_col = in_array('payment_date', $payment_columns) ? 'payment_date' : 
               (in_array('received_at', $payment_columns) ? 'received_at' : 'created_at');
    $method_col = in_array('payment_method', $payment_columns) ? 'payment_method' : 'method';
    $ref_col = in_array('transaction_ref', $payment_columns) ? 'transaction_ref' : 'reference';
    
    if ($has_pledge_payments) {
        $payment_query = "
            SELECT 
                pay.id,
                pay.{$date_col} as payment_date,
                pay.amount,
                pay.{$method_col} as payment_method,
                pay.{$ref_col} as reference,
                pay.status,
                u.name as approver_name,
                'instant' as payment_type
            FROM payments pay
            LEFT JOIN users u ON pay.{$approver_col} = u.id
            WHERE pay.donor_phone = ?
            
            UNION ALL
            
            SELECT 
                pp.id,
                pp.payment_date,
                pp.amount,
                pp.payment_method,
                pp.reference_number as reference,
                pp.status,
                approver.name as approver_name,
                'pledge' as payment_type
            FROM pledge_payments pp
            LEFT JOIN users approver ON pp.approved_by_user_id = approver.id
            WHERE pp.donor_id = ?
            
            ORDER BY payment_date DESC
            LIMIT 10
        ";
        $stmt = $db->prepare($payment_query);
        $stmt->bind_param('si', $donor['phone'], $donor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($pay = $result->fetch_assoc()) {
            $payments[] = $pay;
        }
        $stmt->close();
    }
    
    // 4. Get Payment Plans
    $plans = [];
    if ($db->query("SHOW TABLES LIKE 'donor_payment_plans'")->num_rows > 0) {
        $plan_query = "
            SELECT pp.*, t.name as template_name 
            FROM donor_payment_plans pp
            LEFT JOIN payment_plan_templates t ON pp.template_id = t.id
            WHERE pp.donor_id = ? 
            ORDER BY pp.created_at DESC
        ";
        $stmt = $db->prepare($plan_query);
        $stmt->bind_param('i', $donor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($plan = $result->fetch_assoc()) {
            $plans[] = $plan;
        }
        $stmt->close();
    }
    
    // 5. Get Call History
    $calls = [];
    if ($db->query("SHOW TABLES LIKE 'call_center_sessions'")->num_rows > 0) {
        $call_query = "
            SELECT cs.*, u.name as agent_name 
            FROM call_center_sessions cs
            LEFT JOIN users u ON cs.agent_id = u.id
            WHERE cs.donor_id = ? 
            ORDER BY cs.call_started_at DESC
            LIMIT 5
        ";
        $stmt = $db->prepare($call_query);
        $stmt->bind_param('i', $donor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($call = $result->fetch_assoc()) {
            $calls[] = $call;
        }
        $stmt->close();
    }
    
    // 6. Get Representative Info
    $representative = null;
    $check_rep = $db->query("SHOW COLUMNS FROM donors LIKE 'representative_id'");
    if ($check_rep && $check_rep->num_rows > 0 && !empty($donor['representative_id'])) {
        $rep_query = "SELECT id, name, role, phone FROM church_representatives WHERE id = ?";
        $stmt = $db->prepare($rep_query);
        $stmt->bind_param('i', $donor['representative_id']);
        $stmt->execute();
        $representative = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    
    // 7. Calculate accurate totals (same as view-donor.php)
    $total_pledged = (float)($donor['total_pledged'] ?? 0);
    $total_paid = (float)($donor['total_paid'] ?? 0);
    $balance = (float)($donor['balance'] ?? 0);
    
    // Recalculate if needed
    if ($total_pledged == 0 && $total_paid == 0) {
        // Sum approved pledges
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM pledges WHERE donor_id = ? AND status = 'approved'");
        $stmt->bind_param('i', $donor_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $total_pledged = (float)($row['total'] ?? 0);
        $stmt->close();
        
        // Sum approved payments
        $paid_from_payments = 0;
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE donor_phone = ? AND status = 'approved'");
        $stmt->bind_param('s', $donor['phone']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $paid_from_payments = (float)($row['total'] ?? 0);
        $stmt->close();
        
        // Sum confirmed pledge payments
        $paid_from_pledge_payments = 0;
        if ($has_pledge_payments) {
            $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM pledge_payments WHERE donor_id = ? AND status = 'confirmed'");
            $stmt->bind_param('i', $donor_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $paid_from_pledge_payments = (float)($row['total'] ?? 0);
            $stmt->close();
        }
        
        $total_paid = $paid_from_payments + $paid_from_pledge_payments;
        $balance = max($total_pledged - $total_paid, 0);
    }
    
    // Determine registrar name - fallback to pledge registrar if donor registrar is not set
    $registrar_name = $donor['registrar_name'] ?? null;
    if (empty($registrar_name) && !empty($pledges)) {
        // Use the most recent pledge's registrar
        $registrar_name = $pledges[0]['registrar_name'] ?? null;
    }
    if (empty($registrar_name)) {
        $registrar_name = 'Unknown';
    }
    
    // Language label mapping
    $lang_labels = [
        'en' => 'English',
        'am' => 'Amharic (አማርኛ)',
        'ti' => 'Tigrinya (ትግርኛ)'
    ];
    $preferred_language = $donor['preferred_language'] ?? 'en';
    $language_label = $lang_labels[$preferred_language] ?? 'English';
    
    // Payment method formatting
    $payment_methods = [
        'bank_transfer' => 'Bank Transfer',
        'cash' => 'Cash',
        'card' => 'Card',
        'cheque' => 'Cheque'
    ];
    $payment_method = $donor['preferred_payment_method'] ?? '';
    $payment_method_label = $payment_methods[$payment_method] ?? ucfirst(str_replace('_', ' ', $payment_method));
    
    // Build response
    $response = [
        'success' => true,
        'donor' => [
            'id' => (int)$donor['id'],
            'name' => $donor['name'] ?? '',
            'phone' => $donor['phone'] ?? '',
            'email' => $donor['email'] ?? '',
            'city' => $donor['city'] ?? '',
            'baptism_name' => $donor['baptism_name'] ?? '',
            'donor_type' => $donor['donor_type'] ?? 'pledge',
            'total_pledged' => $total_pledged,
            'total_paid' => $total_paid,
            'balance' => $balance,
            'payment_status' => $donor['payment_status'] ?? 'no_pledge',
            'preferred_language' => $preferred_language,
            'preferred_language_label' => $language_label,
            'preferred_payment_method' => $payment_method,
            'preferred_payment_method_label' => $payment_method_label,
            'church_id' => $donor['church_id'] ?? null,
            'church_name' => $donor['church_name'] ?? '',
            'church_city' => $donor['church_city'] ?? '',
            'registrar_name' => $registrar_name,
            'reference_number' => $reference_number,
            'created_at' => $donor['created_at'] ?? '',
            'sms_opt_in' => (bool)($donor['sms_opt_in'] ?? true),
            'email_opt_in' => (bool)($donor['email_opt_in'] ?? true),
            'flagged_for_followup' => (bool)($donor['flagged_for_followup'] ?? false),
            'followup_priority' => $donor['followup_priority'] ?? 'medium',
            'admin_notes' => $donor['admin_notes'] ?? ''
        ],
        'representative' => $representative ? [
            'id' => (int)$representative['id'],
            'name' => $representative['name'] ?? '',
            'role' => $representative['role'] ?? '',
            'phone' => $representative['phone'] ?? ''
        ] : null,
        'pledges' => array_map(function($p) {
            return [
                'id' => (int)$p['id'],
                'amount' => (float)$p['amount'],
                'status' => $p['status'] ?? '',
                'notes' => $p['notes'] ?? '',
                'registrar_name' => $p['registrar_name'] ?? '',
                'created_at' => $p['created_at'] ?? ''
            ];
        }, $pledges),
        'payments' => array_map(function($p) {
            return [
                'id' => (int)$p['id'],
                'amount' => (float)$p['amount'],
                'payment_method' => $p['payment_method'] ?? '',
                'reference' => $p['reference'] ?? '',
                'status' => $p['status'] ?? '',
                'payment_type' => $p['payment_type'] ?? 'instant',
                'approver_name' => $p['approver_name'] ?? '',
                'payment_date' => $p['payment_date'] ?? ''
            ];
        }, $payments),
        'plans' => array_map(function($p) {
            return [
                'id' => (int)$p['id'],
                'template_name' => $p['template_name'] ?? '',
                'total_amount' => (float)($p['total_amount'] ?? 0),
                'monthly_amount' => (float)($p['monthly_amount'] ?? 0),
                'duration_months' => (int)($p['duration_months'] ?? 0),
                'status' => $p['status'] ?? '',
                'start_date' => $p['start_date'] ?? '',
                'created_at' => $p['created_at'] ?? ''
            ];
        }, $plans),
        'calls' => array_map(function($c) {
            return [
                'id' => (int)$c['id'],
                'agent_name' => $c['agent_name'] ?? '',
                'outcome' => $c['outcome'] ?? '',
                'conversation_stage' => $c['conversation_stage'] ?? '',
                'duration_seconds' => (int)($c['duration_seconds'] ?? 0),
                'call_started_at' => $c['call_started_at'] ?? '',
                'notes' => $c['notes'] ?? ''
            ];
        }, $calls),
        'stats' => [
            'total_pledges' => count($pledges),
            'total_payments' => count($payments),
            'total_plans' => count($plans),
            'total_calls' => count($calls)
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

