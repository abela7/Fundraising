<?php
/**
 * Public Donor Review Report
 * Mobile-first design for sharing with data collectors
 * No authentication required
 */

// Actual donor data from Excel (parsed)
$rawDonors = [
    ['no' => 1, 'name' => 'Like Tiguhan Birhanu', 'phone' => '07473 822244', 'pledge' => '1500', 'method' => 'bank transfer', 'deadline' => '', 'notes' => 'paid £500'],
    ['no' => 2, 'name' => 'Kesis Dagmawi', 'phone' => '07474 962830', 'pledge' => '1500', 'method' => 'bank transfer', 'deadline' => '', 'notes' => 'paid £400'],
    ['no' => 3, 'name' => 'Woinshet t/ Medin', 'phone' => '7932793867', 'pledge' => '500', 'method' => 'bank transfer', 'deadline' => '', 'notes' => 'paid £400'],
    ['no' => 4, 'name' => 'Hiwot', 'phone' => '7508030686', 'pledge' => '1000', 'method' => 'bank transfer', 'deadline' => '', 'notes' => 'paid £250'],
    ['no' => 5, 'name' => 'Geda Gemechu', 'phone' => '7393180103', 'pledge' => '1000', 'method' => 'bank transfer', 'deadline' => '', 'notes' => 'paid all £1,000'],
    ['no' => 6, 'name' => 'Mosisa Hunde', 'phone' => '07404 411392', 'pledge' => '600', 'method' => 'cash', 'deadline' => '', 'notes' => ''],
    ['no' => 7, 'name' => 'Ayelech Habtamu', 'phone' => '7435627896', 'pledge' => '1000', 'method' => 'cash', 'deadline' => '28/06/2025', 'notes' => 'paid all £1,000'],
    ['no' => 8, 'name' => 'Abel and Emuye', 'phone' => '7490447376', 'pledge' => '1000', 'method' => 'bank transfer', 'deadline' => '', 'notes' => 'paidall £1,000'],
    ['no' => 9, 'name' => 'Yohanis Akililu', 'phone' => '7949146267', 'pledge' => '500', 'method' => 'bank transfer', 'deadline' => '', 'notes' => 'paid all £500'],
    ['no' => 10, 'name' => 'Nahom Alemu', 'phone' => '7915459008', 'pledge' => '500', 'method' => '', 'deadline' => '31/5/25', 'notes' => 'paid all £300'],
    ['no' => 11, 'name' => 'Sisay Asefa', 'phone' => '7482767756', 'pledge' => '1000', 'method' => 'cash', 'deadline' => '31/5/25', 'notes' => 'paid £700'],
    ['no' => 12, 'name' => 'Roza Hunde', 'phone' => '7902944713', 'pledge' => '1000', 'method' => 'cash', 'deadline' => '28/06/2025', 'notes' => 'paid all £ 1,000'],
    ['no' => 13, 'name' => 'Selam', 'phone' => '', 'pledge' => '200', 'method' => '', 'deadline' => '', 'notes' => 'paid £200'],
    ['no' => 14, 'name' => 'Yeshiwork', 'phone' => '7878567049', 'pledge' => '100', 'method' => '', 'deadline' => '', 'notes' => 'paid all £200'],
    ['no' => 15, 'name' => 'Mekdes Tewolde', 'phone' => '7449884424', 'pledge' => '1000', 'method' => '', 'deadline' => '', 'notes' => 'paid all £1,000'],
    ['no' => 16, 'name' => 'Mihret Birhanu', 'phone' => '7311305605', 'pledge' => '500', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 17, 'name' => 'Etsub', 'phone' => '7931261431', 'pledge' => '1000', 'method' => 'cash', 'deadline' => '4 months time', 'notes' => 'paid £500'],
    ['no' => 18, 'name' => 'yalew mekonnen', 'phone' => '7440347838', 'pledge' => '500+500', 'method' => 'bank transfer', 'deadline' => '', 'notes' => 'paid all £1,000'],
    ['no' => 19, 'name' => 'Yared Syoum', 'phone' => '7477732373', 'pledge' => '1500', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 20, 'name' => 'Dereje Argaw', 'phone' => '7383333847', 'pledge' => '1000', 'method' => 'bank transfer', 'deadline' => 'monthly', 'notes' => 'paid £ 700'],
    ['no' => 21, 'name' => 'Kakidan Melkamu', 'phone' => '7311114440', 'pledge' => '500', 'method' => 'bank transfer', 'deadline' => '', 'notes' => 'paid all £ 500'],
    ['no' => 22, 'name' => 'Aster', 'phone' => '7508993242', 'pledge' => '', 'method' => 'bank transfer', 'deadline' => '', 'notes' => ''],
    ['no' => 23, 'name' => 'Tesfaye Daba', 'phone' => '7944693263', 'pledge' => '100', 'method' => 'bank transfer', 'deadline' => 'this month', 'notes' => ''],
    ['no' => 24, 'name' => 'Girma Birhan', 'phone' => '7873725678', 'pledge' => '300', 'method' => 'bank transfer', 'deadline' => '', 'notes' => 'paid'],
    ['no' => 25, 'name' => 'Gabreiel Mader', 'phone' => '7388418902', 'pledge' => '200+400', 'method' => 'bank transfer', 'deadline' => 'monthly', 'notes' => 'paid £ 100'],
    ['no' => 26, 'name' => 'Yonatan Dawit', 'phone' => '7828556674', 'pledge' => '50', 'method' => 'bank transfer', 'deadline' => 'july', 'notes' => ''],
    ['no' => 27, 'name' => 'Fiseha Habtamu', 'phone' => '7415217801', 'pledge' => '1000', 'method' => 'bank transfer', 'deadline' => 'split in half', 'notes' => 'paid all £1,000'],
    ['no' => 28, 'name' => 'Eyerusalem and Tsegaye', 'phone' => '7719597801', 'pledge' => '500', 'method' => 'bank transfer', 'deadline' => 'monthly', 'notes' => ''],
    ['no' => 29, 'name' => 'Maranata Mehari', 'phone' => '7387173507', 'pledge' => '500', 'method' => 'cash', 'deadline' => '', 'notes' => 'paid all £500'],
    ['no' => 30, 'name' => 'Henok Birhane', 'phone' => '7495039019', 'pledge' => '500', 'method' => 'cash', 'deadline' => 'monthly', 'notes' => ''],
    ['no' => 31, 'name' => 'Helen Tewolde', 'phone' => '7378503752', 'pledge' => '500', 'method' => 'cash', 'deadline' => 'monthly', 'notes' => ''],
    ['no' => 32, 'name' => 'Roza Awot', 'phone' => '7378503752', 'pledge' => '200', 'method' => 'cash', 'deadline' => 'not decided', 'notes' => 'From Manchester'],
    ['no' => 33, 'name' => 'Mulu Sate Mola', 'phone' => '7770075784', 'pledge' => '200', 'method' => 'cash', 'deadline' => '3 month time', 'notes' => ''],
    ['no' => 34, 'name' => 'Meaza and Mahlet', 'phone' => '7438156695', 'pledge' => '750', 'method' => 'cash', 'deadline' => 'Meaza Abrham', 'notes' => 'paid all £300'],
    ['no' => 35, 'name' => 'Hailemichael', 'phone' => '74554767141', 'pledge' => '500', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 36, 'name' => 'Maya Mangistu', 'phone' => '7888139734', 'pledge' => '200', 'method' => 'cash', 'deadline' => '', 'notes' => 'paid all £200'],
    ['no' => 37, 'name' => 'Saba Mekonen', 'phone' => '', 'pledge' => '300', 'method' => '', 'deadline' => '', 'notes' => 'paid all £300'],
    ['no' => 38, 'name' => 'Michael Nigusie', 'phone' => '7415329333', 'pledge' => '1000', 'method' => 'cash', 'deadline' => 'monthly', 'notes' => 'start June'],
    ['no' => 39, 'name' => 'W/Michael', 'phone' => '', 'pledge' => '35', 'method' => '', 'deadline' => '', 'notes' => 'paid all £ 35'],
    ['no' => 40, 'name' => 'Samuel', 'phone' => '7453303053', 'pledge' => '1000', 'method' => 'cash', 'deadline' => 'monthly', 'notes' => 'start June'],
    ['no' => 41, 'name' => 'Beti', 'phone' => '', 'pledge' => '110', 'method' => '', 'deadline' => '', 'notes' => 'paid'],
    ['no' => 42, 'name' => 'Abel', 'phone' => '7360436171', 'pledge' => '500', 'method' => '', 'deadline' => '', 'notes' => "didn't answer"],
    ['no' => 43, 'name' => 'Saniat', 'phone' => 'C/o 07932793867', 'pledge' => '200', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 44, 'name' => 'Milana Birhane', 'phone' => '735957727', 'pledge' => '500', 'method' => '', 'deadline' => '', 'notes' => '1 number is missing from the phone number'],
    ['no' => 45, 'name' => 'Elsabeth Mitiku', 'phone' => '7365938258', 'pledge' => '300', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 46, 'name' => 'Ermias Tekalu', 'phone' => '7415005376', 'pledge' => '500', 'method' => '', 'deadline' => '', 'notes' => 'paid all £ 500'],
    ['no' => 47, 'name' => 'mikael tesfaye', 'phone' => '7476336051', 'pledge' => '1000', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 48, 'name' => 'megabe hadis Daniel', 'phone' => '7401399936', 'pledge' => '300', 'method' => 'bank transfer', 'deadline' => '3 month time', 'notes' => 'paid £150'],
    ['no' => 49, 'name' => 'Filmon tedros', 'phone' => 'C/o 07460485935 Rahel', 'pledge' => '500', 'method' => 'bank transfer', 'deadline' => '', 'notes' => 'paid all £500'],
    ['no' => 50, 'name' => 'kbreab /welde gebreal/', 'phone' => '7459259509', 'pledge' => '1000', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 51, 'name' => 'hagos tsegaye', 'phone' => '7868671227', 'pledge' => '1000', 'method' => 'cash', 'deadline' => '', 'notes' => 'paid £ 200'],
    ['no' => 52, 'name' => 'Lidya Hagos', 'phone' => '7706085397', 'pledge' => '250', 'method' => '', 'deadline' => '', 'notes' => 'paid all £250'],
    ['no' => 53, 'name' => 'fqrte Gebrel', 'phone' => '7933293944', 'pledge' => '500', 'method' => '', 'deadline' => '', 'notes' => 'paid all £500'],
    ['no' => 54, 'name' => 'Genet solomon', 'phone' => '7931796244', 'pledge' => '100', 'method' => '', 'deadline' => '', 'notes' => 'paid all £100'],
    ['no' => 55, 'name' => 'Filmon G/ezgi', 'phone' => '7476743908', 'pledge' => '500', 'method' => '', 'deadline' => '', 'notes' => 'paid all £500'],
    ['no' => 56, 'name' => 'Mahilet Hagos', 'phone' => '7438253791', 'pledge' => '300', 'method' => '', 'deadline' => '', 'notes' => 'paid all £300'],
    ['no' => 57, 'name' => 'yared Habtemaryam', 'phone' => '7392205538', 'pledge' => '100', 'method' => '', 'deadline' => '', 'notes' => 'paid all £100'],
    ['no' => 58, 'name' => 'Eyobe zelalem', 'phone' => '7466690312', 'pledge' => '600', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 59, 'name' => 'kibrom getchew', 'phone' => '7495760372', 'pledge' => '600', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 60, 'name' => 'h/mariam tesfe', 'phone' => '7469481854', 'pledge' => '600', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 61, 'name' => 'yontan', 'phone' => '7516172076', 'pledge' => '1000', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 62, 'name' => 'mesfin tefera (blackpool)', 'phone' => '7386208291', 'pledge' => '600', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 63, 'name' => 'amanuel', 'phone' => '7392364310', 'pledge' => '500', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 64, 'name' => 'kiflemicheal (henok)', 'phone' => '7411002386', 'pledge' => '500', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 65, 'name' => 'ashenafi bereda', 'phone' => '7739440766', 'pledge' => '500', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 66, 'name' => 'tewodros akililu', 'phone' => '7456574276', 'pledge' => '500', 'method' => '', 'deadline' => '', 'notes' => 'paid £250'],
    ['no' => 67, 'name' => 'jemla sefa', 'phone' => '7413117896', 'pledge' => '600', 'method' => '', 'deadline' => '', 'notes' => 'paid all £600'],
    ['no' => 68, 'name' => 'saba mekonnen', 'phone' => '7727346626', 'pledge' => '300', 'method' => '', 'deadline' => '', 'notes' => 'paid all £300'],
    ['no' => 69, 'name' => 'mesert h/selasie (grace)', 'phone' => '7500657641', 'pledge' => '5000', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 70, 'name' => 'abebeau abera', 'phone' => '7513816289', 'pledge' => '1000', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 71, 'name' => 'daniel mesfin', 'phone' => '7455805157', 'pledge' => '1000', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 72, 'name' => 'yared kidane', 'phone' => '7307718126', 'pledge' => '1000', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 73, 'name' => 'haile alemu', 'phone' => '7857223571', 'pledge' => '900', 'method' => '', 'deadline' => '', 'notes' => 'paid £100'],
    ['no' => 74, 'name' => 'dejene', 'phone' => '7449212748', 'pledge' => '1000', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 75, 'name' => 'betlehem alemayehu (getacheu)', 'phone' => '7476884024', 'pledge' => '500', 'method' => '', 'deadline' => '', 'notes' => 'paid £90'],
    ['no' => 76, 'name' => 'selamawit afeworkie', 'phone' => '7946869284', 'pledge' => '1100', 'method' => '', 'deadline' => '', 'notes' => 'paid all £1,100'],
    ['no' => 77, 'name' => 'haile yesus (barber)', 'phone' => '', 'pledge' => '150', 'method' => 'cash', 'deadline' => '', 'notes' => 'paid all £150'],
    ['no' => 78, 'name' => 'Daniel kassa', 'phone' => '', 'pledge' => '1000', 'method' => 'cash', 'deadline' => '31/5/25 _28/6/25', 'notes' => 'paid £800'],
    ['no' => 79, 'name' => 'Tesfaye mezmuran', 'phone' => '', 'pledge' => '500', 'method' => 'cash', 'deadline' => '7/28/2026', 'notes' => 'paid all £500'],
    ['no' => 80, 'name' => 'Frehiwot', 'phone' => '7981670102', 'pledge' => '300', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 81, 'name' => 'Elsa Tadesse', 'phone' => '7458985366', 'pledge' => '200', 'method' => 'bank transfer', 'deadline' => '25/7/25', 'notes' => 'paid£50'],
    ['no' => 82, 'name' => 'Tesfanesh Megersa', 'phone' => '7479334292', 'pledge' => '100', 'method' => '', 'deadline' => '', 'notes' => ''],
    ['no' => 83, 'name' => 'Tegist Kassa', 'phone' => '7480933736', 'pledge' => '50', 'method' => 'bank transfer', 'deadline' => '', 'notes' => 'paid all £ 50'],
    ['no' => 84, 'name' => 'Woleteslassie', 'phone' => '7588152998', 'pledge' => '50', 'method' => '', 'deadline' => '', 'notes' => 'paid £20'],
    ['no' => 85, 'name' => 'Woletemariam', 'phone' => '7476103881', 'pledge' => '50', 'method' => '', 'deadline' => '15/7/25', 'notes' => 'paid£10'],
    ['no' => 86, 'name' => 'Hanock Philemon', 'phone' => '7904936740', 'pledge' => '1000', 'method' => 'bank transfer', 'deadline' => '', 'notes' => ''],
    ['no' => 87, 'name' => 'Ababia Gemechu', 'phone' => '7749027431', 'pledge' => '1000', 'method' => 'bank transfer', 'deadline' => '', 'notes' => ''],
    ['no' => 88, 'name' => 'Simon Yohanes', 'phone' => '740603410', 'pledge' => '300', 'method' => 'bank transfer', 'deadline' => '', 'notes' => 'paid all £ 300'],
    ['no' => 89, 'name' => 'Semhar Abrhame', 'phone' => '', 'pledge' => '600', 'method' => 'cash', 'deadline' => '', 'notes' => 'paid £ 200'],
    ['no' => 90, 'name' => 'Tsehaye getnet', 'phone' => '', 'pledge' => '200', 'method' => 'cash', 'deadline' => '', 'notes' => 'paid all £200'],
    ['no' => 91, 'name' => 'Yonas Legese', 'phone' => '', 'pledge' => '1000', 'method' => 'cash', 'deadline' => '', 'notes' => 'paid all £1,000'],
    ['no' => 92, 'name' => 'Beza', 'phone' => '', 'pledge' => '100', 'method' => '', 'deadline' => '', 'notes' => 'paid all £ 100'],
    ['no' => 93, 'name' => 'Georgia & Muhammed', 'phone' => '', 'pledge' => '100', 'method' => 'cash', 'deadline' => '', 'notes' => 'paid all £100'],
    ['no' => 94, 'name' => 'Eden Mehari', 'phone' => '7961474962', 'pledge' => '', 'method' => 'cash', 'deadline' => '', 'notes' => 'paid£100'],
    ['no' => 95, 'name' => 'Eyarusalem Hagos', 'phone' => '7951545098', 'pledge' => '500 +200', 'method' => 'cash', 'deadline' => '', 'notes' => 'paid all£500 buli £200 auction'],
    ['no' => 96, 'name' => 'Tewodros Ferewe', 'phone' => '7480973939', 'pledge' => '', 'method' => '', 'deadline' => '', 'notes' => ''],
];

// Process and identify issues
$donors = [];
$phoneTracker = []; // Track phone numbers for duplicates

// First pass: count phones
foreach ($rawDonors as $d) {
    $cleanPhone = preg_replace('/[^0-9]/', '', $d['phone']);
    if (strlen($cleanPhone) >= 10) {
        $phoneTracker[$cleanPhone] = ($phoneTracker[$cleanPhone] ?? 0) + 1;
    }
}

// Second pass: analyze each donor
foreach ($rawDonors as $d) {
    $donor = $d;
    $donor['issues'] = [];
    $donor['issue_level'] = 'info';
    
    // Parse pledge amount (handle combined amounts like "500+500")
    $pledgeStr = $d['pledge'];
    $pledgeAmount = 0;
    if (preg_match_all('/(\d+)/', $pledgeStr, $matches)) {
        foreach ($matches[1] as $num) {
            $pledgeAmount += (float)$num;
        }
    }
    $donor['pledge_amount'] = $pledgeAmount;
    
    // Parse paid amount from notes
    $paidAmount = 0;
    $notes = strtolower($d['notes']);
    if (preg_match('/paid\s*(?:all\s*)?[£]?\s*([\d,]+(?:\.\d{2})?)/i', $d['notes'], $match)) {
        $paidAmount = (float)str_replace(',', '', $match[1]);
    } elseif (stripos($notes, 'paid') !== false && stripos($notes, 'all') !== false) {
        $paidAmount = $pledgeAmount;
    } elseif ($notes === 'paid') {
        $paidAmount = $pledgeAmount;
    }
    $donor['paid_amount'] = $paidAmount;
    $donor['balance_amount'] = max(0, $pledgeAmount - $paidAmount);
    
    // ===== ISSUE DETECTION =====
    
    // 1. NO PHONE - Critical
    $phone = trim($d['phone']);
    if (empty($phone)) {
        $donor['issues'][] = ['type' => 'danger', 'icon' => 'phone-slash', 'text' => 'No phone number - cannot contact'];
        $donor['issue_level'] = 'danger';
    } 
    // Phone has "C/o" (care of another person)
    elseif (stripos($phone, 'c/o') !== false) {
        $donor['issues'][] = ['type' => 'warning', 'icon' => 'user-friends', 'text' => 'Uses someone else\'s phone: ' . $phone];
        if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
    }
    // Phone has letters (corrupted)
    elseif (preg_match('/[a-zA-Z]/', $phone)) {
        $donor['issues'][] = ['type' => 'warning', 'icon' => 'exclamation-triangle', 'text' => 'Phone has text: "' . $phone . '"'];
        if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
    }
    // Phone too short or too long
    else {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($cleanPhone) < 10) {
            $donor['issues'][] = ['type' => 'warning', 'icon' => 'phone', 'text' => 'Phone too short (' . strlen($cleanPhone) . ' digits): ' . $phone];
            if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
        } elseif (strlen($cleanPhone) > 11) {
            $donor['issues'][] = ['type' => 'warning', 'icon' => 'phone', 'text' => 'Phone too long (' . strlen($cleanPhone) . ' digits): ' . $phone];
            if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
        }
        // Check for duplicates
        if (strlen($cleanPhone) >= 10 && isset($phoneTracker[$cleanPhone]) && $phoneTracker[$cleanPhone] > 1) {
            $donor['issues'][] = ['type' => 'info', 'icon' => 'clone', 'text' => 'Shared phone number (used by ' . $phoneTracker[$cleanPhone] . ' donors)'];
        }
    }
    
    // 2. NO PLEDGE AMOUNT - Critical
    if ($pledgeAmount == 0 && empty($d['pledge'])) {
        $donor['issues'][] = ['type' => 'danger', 'icon' => 'pound-sign', 'text' => 'No pledge amount recorded'];
        $donor['issue_level'] = 'danger';
    }
    
    // 3. Combined/Complex pledge amount
    if (strpos($pledgeStr, '+') !== false) {
        $donor['issues'][] = ['type' => 'info', 'icon' => 'calculator', 'text' => 'Combined pledge: ' . $pledgeStr . ' = £' . number_format($pledgeAmount)];
    }
    
    // 4. Overpaid
    if ($paidAmount > $pledgeAmount && $pledgeAmount > 0) {
        $overpay = $paidAmount - $pledgeAmount;
        $donor['issues'][] = ['type' => 'warning', 'icon' => 'arrow-up', 'text' => 'Overpaid by £' . number_format($overpay) . ' - needs allocation'];
        if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
    }
    
    // 5. Payment mismatch (paid £200 but pledge is £100)
    if ($paidAmount > 0 && $pledgeAmount > 0 && $paidAmount > $pledgeAmount * 2) {
        $donor['issues'][] = ['type' => 'warning', 'icon' => 'not-equal', 'text' => 'Payment (£' . number_format($paidAmount) . ') > 2x pledge (£' . number_format($pledgeAmount) . ')'];
        if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
    }
    
    // 6. Notes requiring attention
    $notesLower = strtolower($d['notes']);
    if (!empty($d['notes'])) {
        // Specific attention-needed notes
        if (stripos($d['notes'], "didn't answer") !== false || stripos($d['notes'], 'didnt answer') !== false) {
            $donor['issues'][] = ['type' => 'warning', 'icon' => 'phone-alt', 'text' => 'Did not answer phone'];
            if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
        }
        if (stripos($d['notes'], 'missing') !== false) {
            $donor['issues'][] = ['type' => 'warning', 'icon' => 'question-circle', 'text' => 'Note: ' . $d['notes']];
            if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
        }
        if (stripos($d['notes'], 'manchester') !== false || stripos($d['notes'], 'blackpool') !== false) {
            $donor['issues'][] = ['type' => 'info', 'icon' => 'map-marker-alt', 'text' => 'Different location: ' . $d['notes']];
        }
        if (stripos($d['notes'], 'auction') !== false) {
            $donor['issues'][] = ['type' => 'info', 'icon' => 'gavel', 'text' => 'Includes auction payment: ' . $d['notes']];
        }
        if (stripos($d['notes'], 'start june') !== false || stripos($d['notes'], 'start june') !== false) {
            $donor['issues'][] = ['type' => 'info', 'icon' => 'calendar', 'text' => 'Scheduled start: ' . $d['notes']];
        }
    }
    
    // 7. High value pledge (£1500+)
    if ($pledgeAmount >= 1500) {
        $donor['issues'][] = ['type' => 'info', 'icon' => 'star', 'text' => 'High value pledge: £' . number_format($pledgeAmount)];
    }
    
    // 8. Very high value (£5000+)
    if ($pledgeAmount >= 5000) {
        $donor['issues'][] = ['type' => 'warning', 'icon' => 'gem', 'text' => 'VIP Donor - Very high pledge: £' . number_format($pledgeAmount)];
        if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
    }
    
    // 9. Unusual amounts
    if ($pledgeAmount > 0 && !in_array($pledgeAmount, [35, 50, 100, 110, 150, 200, 250, 300, 400, 500, 600, 700, 750, 900, 1000, 1100, 1500, 5000])) {
        // Check if it's not a common amount
        $unusualAmounts = [35, 110, 150, 250, 750, 900, 1100];
        if (!in_array($pledgeAmount, [100, 200, 300, 400, 500, 600, 1000, 1500, 5000])) {
            $donor['issues'][] = ['type' => 'info', 'icon' => 'info-circle', 'text' => 'Non-standard pledge amount: £' . number_format($pledgeAmount)];
        }
    }
    
    $donors[] = $donor;
}

// Filter to only donors with issues
$donorsWithIssues = array_filter($donors, fn($d) => !empty($d['issues']));

// Sort by issue level (danger first, then warning, then info)
usort($donorsWithIssues, function($a, $b) {
    $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
    $levelA = $order[$a['issue_level']] ?? 3;
    $levelB = $order[$b['issue_level']] ?? 3;
    if ($levelA !== $levelB) return $levelA <=> $levelB;
    return $a['no'] <=> $b['no'];
});

// Count by level
$dangerCount = count(array_filter($donorsWithIssues, fn($d) => $d['issue_level'] === 'danger'));
$warningCount = count(array_filter($donorsWithIssues, fn($d) => $d['issue_level'] === 'warning'));
$infoCount = count(array_filter($donorsWithIssues, fn($d) => $d['issue_level'] === 'info'));

// Helper function
function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#1a1a2e">
    <title>📋 Donor Review Report</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-dark: #0f0f1a;
            --bg-card: #1a1a2e;
            --bg-card-hover: #252542;
            --text-primary: #ffffff;
            --text-secondary: #a0a0b8;
            --text-muted: #6b6b80;
            --accent-blue: #4f8cff;
            --accent-green: #2ecc71;
            --accent-orange: #f39c12;
            --accent-red: #e74c3c;
            --border-color: #2a2a45;
            --danger-bg: rgba(231, 76, 60, 0.15);
            --warning-bg: rgba(243, 156, 18, 0.15);
            --info-bg: rgba(79, 140, 255, 0.15);
            --success-bg: rgba(46, 204, 113, 0.15);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-dark);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #1a1a2e 0%, #2d2d4a 100%);
            padding: 20px 16px;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid var(--border-color);
        }
        
        .header-content {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .header h1 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .header h1 i {
            color: var(--accent-blue);
        }
        
        .header p {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .church-name {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 4px;
        }
        
        /* Stats Bar */
        .stats-bar {
            display: flex;
            gap: 12px;
            padding: 16px;
            max-width: 600px;
            margin: 0 auto;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .stat-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 50px;
            font-size: 0.875rem;
            font-weight: 600;
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .stat-pill.danger {
            background: var(--danger-bg);
            color: var(--accent-red);
            border: 1px solid rgba(231, 76, 60, 0.3);
        }
        
        .stat-pill.warning {
            background: var(--warning-bg);
            color: var(--accent-orange);
            border: 1px solid rgba(243, 156, 18, 0.3);
        }
        
        .stat-pill.info {
            background: var(--info-bg);
            color: var(--accent-blue);
            border: 1px solid rgba(79, 140, 255, 0.3);
        }
        
        .stat-pill .count {
            background: rgba(255,255,255,0.15);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
        }
        
        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 8px;
            padding: 0 16px 16px;
            max-width: 600px;
            margin: 0 auto;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .filter-tab {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 500;
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }
        
        .filter-tab.active {
            background: var(--accent-blue);
            color: white;
            border-color: var(--accent-blue);
        }
        
        .filter-tab:hover:not(.active) {
            background: var(--bg-card-hover);
        }
        
        /* Main Content */
        .main {
            padding: 0 16px 100px;
            max-width: 600px;
            margin: 0 auto;
        }
        
        /* Donor Card */
        .donor-card {
            background: var(--bg-card);
            border-radius: 16px;
            margin-bottom: 16px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            transition: all 0.2s;
        }
        
        .donor-card.danger {
            border-left: 4px solid var(--accent-red);
        }
        
        .donor-card.warning {
            border-left: 4px solid var(--accent-orange);
        }
        
        .donor-card.info {
            border-left: 4px solid var(--accent-blue);
        }
        
        .donor-header {
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }
        
        .donor-info {
            flex: 1;
            min-width: 0;
        }
        
        .donor-number {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .donor-name {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
            word-break: break-word;
        }
        
        .donor-phone {
            font-size: 0.85rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .donor-phone a {
            color: var(--accent-blue);
            text-decoration: none;
        }
        
        .donor-phone.error {
            color: var(--accent-red);
        }
        
        .donor-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }
        
        .donor-badge.danger {
            background: var(--danger-bg);
            color: var(--accent-red);
        }
        
        .donor-badge.warning {
            background: var(--warning-bg);
            color: var(--accent-orange);
        }
        
        .donor-badge.info {
            background: var(--info-bg);
            color: var(--accent-blue);
        }
        
        /* Financial Summary */
        .financial-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1px;
            background: var(--border-color);
            border-top: 1px solid var(--border-color);
        }
        
        .financial-item {
            background: var(--bg-card);
            padding: 12px;
            text-align: center;
        }
        
        .financial-label {
            font-size: 0.65rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .financial-value {
            font-size: 1rem;
            font-weight: 700;
        }
        
        .financial-value.pledge { color: var(--accent-blue); }
        .financial-value.paid { color: var(--accent-green); }
        .financial-value.balance { color: var(--accent-orange); }
        .financial-value.zero { color: var(--text-muted); }
        
        /* Issues List */
        .issues-list {
            padding: 12px 16px 16px;
            border-top: 1px solid var(--border-color);
        }
        
        .issues-title {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .issue-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            margin-bottom: 8px;
            font-size: 0.85rem;
        }
        
        .issue-item:last-child {
            margin-bottom: 0;
        }
        
        .issue-item.danger {
            background: var(--danger-bg);
            color: #f5a5a0;
        }
        
        .issue-item.warning {
            background: var(--warning-bg);
            color: #f8d49a;
        }
        
        .issue-item.info {
            background: var(--info-bg);
            color: #9ac4ff;
        }
        
        .issue-item i {
            margin-top: 2px;
            flex-shrink: 0;
            width: 16px;
            text-align: center;
        }
        
        .issue-item.danger i { color: var(--accent-red); }
        .issue-item.warning i { color: var(--accent-orange); }
        .issue-item.info i { color: var(--accent-blue); }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }
        
        .empty-state i {
            font-size: 3rem;
            color: var(--accent-green);
            margin-bottom: 16px;
        }
        
        .empty-state h3 {
            font-size: 1.25rem;
            margin-bottom: 8px;
            color: var(--text-primary);
        }
        
        /* Footer */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, var(--bg-dark) 60%, transparent);
            padding: 40px 16px 20px;
            text-align: center;
        }
        
        .footer-content {
            max-width: 600px;
            margin: 0 auto;
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        
        .footer-btn {
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .footer-btn.primary {
            background: var(--accent-blue);
            color: white;
        }
        
        .footer-btn.secondary {
            background: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 4px;
            height: 4px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-dark);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 4px;
        }
        
        /* Hide elements based on filter */
        .donor-card.hidden {
            display: none;
        }
        
        /* Pulse animation for critical items */
        @keyframes pulse-border {
            0%, 100% { border-left-color: var(--accent-red); }
            50% { border-left-color: rgba(231, 76, 60, 0.4); }
        }
        
        .donor-card.danger.pulse {
            animation: pulse-border 2s infinite;
        }
        
        /* Print styles */
        @media print {
            .header { position: relative; }
            .footer { display: none; }
            .donor-card { break-inside: avoid; }
        }
        
        /* Call button */
        .call-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--accent-green);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
            margin-left: 8px;
        }
        
        .call-btn:hover {
            background: #27ae60;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1><i class="fas fa-clipboard-check"></i> Donor Review Report</h1>
            <p>Donors requiring attention • <?php echo count($donorsWithIssues); ?> of <?php echo count($donors); ?> donors</p>
            <p class="church-name">Liverpool Abune Teklehaymanot EOTC • Generated <?php echo date('j M Y, g:i A'); ?></p>
        </div>
    </header>
    
    <div class="stats-bar">
        <div class="stat-pill danger">
            <i class="fas fa-exclamation-circle"></i>
            Critical
            <span class="count"><?php echo $dangerCount; ?></span>
        </div>
        <div class="stat-pill warning">
            <i class="fas fa-exclamation-triangle"></i>
            Warnings
            <span class="count"><?php echo $warningCount; ?></span>
        </div>
        <div class="stat-pill info">
            <i class="fas fa-info-circle"></i>
            Info
            <span class="count"><?php echo $infoCount; ?></span>
        </div>
    </div>
    
    <div class="filter-tabs">
        <button class="filter-tab active" data-filter="all">All (<?php echo count($donorsWithIssues); ?>)</button>
        <button class="filter-tab" data-filter="danger">Critical (<?php echo $dangerCount; ?>)</button>
        <button class="filter-tab" data-filter="warning">Warning (<?php echo $warningCount; ?>)</button>
        <button class="filter-tab" data-filter="info">Info (<?php echo $infoCount; ?>)</button>
    </div>
    
    <main class="main">
        <?php if (empty($donorsWithIssues)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h3>All Clear!</h3>
                <p>No donors require special attention at this time.</p>
            </div>
        <?php else: ?>
            <?php foreach ($donorsWithIssues as $donor): ?>
                <?php 
                    $phone = trim($donor['phone']);
                    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
                    $hasValidPhone = strlen($cleanPhone) >= 10 && strlen($cleanPhone) <= 11 && !preg_match('/[a-zA-Z]/', $phone);
                    if ($hasValidPhone && !str_starts_with($cleanPhone, '0') && !str_starts_with($cleanPhone, '44')) {
                        $cleanPhone = '0' . $cleanPhone;
                    }
                ?>
                <div class="donor-card <?php echo h($donor['issue_level']); ?> <?php echo $donor['issue_level'] === 'danger' ? 'pulse' : ''; ?>" data-level="<?php echo h($donor['issue_level']); ?>">
                    <div class="donor-header">
                        <div class="donor-info">
                            <div class="donor-number">Donor #<?php echo $donor['no']; ?></div>
                            <div class="donor-name"><?php echo h($donor['name']); ?></div>
                            <?php if ($hasValidPhone): ?>
                                <div class="donor-phone">
                                    <i class="fas fa-phone"></i>
                                    <a href="tel:<?php echo h($cleanPhone); ?>"><?php echo h($phone); ?></a>
                                    <a href="tel:<?php echo h($cleanPhone); ?>" class="call-btn">
                                        <i class="fas fa-phone-alt"></i> Call
                                    </a>
                                </div>
                            <?php elseif (!empty($phone)): ?>
                                <div class="donor-phone error">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <?php echo h($phone); ?>
                                </div>
                            <?php else: ?>
                                <div class="donor-phone error">
                                    <i class="fas fa-phone-slash"></i>
                                    No phone number
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="donor-badge <?php echo h($donor['issue_level']); ?>">
                            <?php 
                            echo $donor['issue_level'] === 'danger' ? 'Critical' : 
                                 ($donor['issue_level'] === 'warning' ? 'Warning' : 'Review');
                            ?>
                        </div>
                    </div>
                    
                    <div class="financial-row">
                        <div class="financial-item">
                            <div class="financial-label">Pledge</div>
                            <div class="financial-value pledge <?php echo $donor['pledge_amount'] == 0 ? 'zero' : ''; ?>">
                                £<?php echo number_format($donor['pledge_amount'], 0); ?>
                            </div>
                        </div>
                        <div class="financial-item">
                            <div class="financial-label">Paid</div>
                            <div class="financial-value paid <?php echo $donor['paid_amount'] == 0 ? 'zero' : ''; ?>">
                                £<?php echo number_format($donor['paid_amount'], 0); ?>
                            </div>
                        </div>
                        <div class="financial-item">
                            <div class="financial-label">Balance</div>
                            <div class="financial-value balance <?php echo $donor['balance_amount'] == 0 ? 'zero' : ''; ?>">
                                £<?php echo number_format($donor['balance_amount'], 0); ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="issues-list">
                        <div class="issues-title">
                            <i class="fas fa-flag"></i>
                            Issues (<?php echo count($donor['issues']); ?>)
                        </div>
                        <?php foreach ($donor['issues'] as $issue): ?>
                            <div class="issue-item <?php echo h($issue['type']); ?>">
                                <i class="fas fa-<?php echo h($issue['icon'] ?? 'info-circle'); ?>"></i>
                                <span><?php echo h($issue['text']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
    
    <div class="footer">
        <div class="footer-content">
            <button class="footer-btn secondary" onclick="window.scrollTo({top: 0, behavior: 'smooth'})">
                <i class="fas fa-arrow-up"></i>
                Top
            </button>
            <button class="footer-btn primary" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i>
                Refresh
            </button>
        </div>
    </div>
    
    <script>
        // Filter functionality
        document.querySelectorAll('.filter-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const filter = this.dataset.filter;
                
                // Update active tab
                document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Filter cards
                document.querySelectorAll('.donor-card').forEach(card => {
                    if (filter === 'all' || card.dataset.level === filter) {
                        card.classList.remove('hidden');
                    } else {
                        card.classList.add('hidden');
                    }
                });
            });
        });
        
        // Pull to refresh (mobile)
        let touchStart = 0;
        document.addEventListener('touchstart', e => {
            touchStart = e.changedTouches[0].screenY;
        });
        
        document.addEventListener('touchend', e => {
            const touchEnd = e.changedTouches[0].screenY;
            if (window.scrollY === 0 && touchEnd - touchStart > 100) {
                location.reload();
            }
        });
    </script>
</body>
</html>
