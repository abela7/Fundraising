<?php
/**
 * Public Donor Review Report
 * Mobile-first design for sharing with data collectors
 * No authentication required
 * 
 * Uses church branding from main website
 */

// Prevent search engine indexing
header('X-Robots-Tag: noindex, nofollow');

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
$phoneTracker = [];

foreach ($rawDonors as $d) {
    $cleanPhone = preg_replace('/[^0-9]/', '', $d['phone']);
    if (strlen($cleanPhone) >= 10) {
        $phoneTracker[$cleanPhone] = ($phoneTracker[$cleanPhone] ?? 0) + 1;
    }
}

foreach ($rawDonors as $d) {
    $donor = $d;
    $donor['issues'] = [];
    $donor['issue_level'] = 'info';
    
    $pledgeStr = $d['pledge'];
    $pledgeAmount = 0;
    if (preg_match_all('/(\d+)/', $pledgeStr, $matches)) {
        foreach ($matches[1] as $num) {
            $pledgeAmount += (float)$num;
        }
    }
    $donor['pledge_amount'] = $pledgeAmount;
    
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
    
    $phone = trim($d['phone']);
    if (empty($phone)) {
        $donor['issues'][] = ['type' => 'danger', 'icon' => 'phone-slash', 'text' => 'No phone number - cannot contact'];
        $donor['issue_level'] = 'danger';
    } elseif (stripos($phone, 'c/o') !== false) {
        $donor['issues'][] = ['type' => 'warning', 'icon' => 'user-friends', 'text' => 'Uses someone else\'s phone: ' . $phone];
        if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
    } elseif (preg_match('/[a-zA-Z]/', $phone)) {
        $donor['issues'][] = ['type' => 'warning', 'icon' => 'exclamation-triangle', 'text' => 'Phone has text: "' . $phone . '"'];
        if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
    } else {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($cleanPhone) < 10) {
            $donor['issues'][] = ['type' => 'warning', 'icon' => 'phone', 'text' => 'Phone too short (' . strlen($cleanPhone) . ' digits)'];
            if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
        } elseif (strlen($cleanPhone) > 11) {
            $donor['issues'][] = ['type' => 'warning', 'icon' => 'phone', 'text' => 'Phone too long (' . strlen($cleanPhone) . ' digits)'];
            if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
        }
        if (strlen($cleanPhone) >= 10 && isset($phoneTracker[$cleanPhone]) && $phoneTracker[$cleanPhone] > 1) {
            $donor['issues'][] = ['type' => 'info', 'icon' => 'clone', 'text' => 'Shared phone (' . $phoneTracker[$cleanPhone] . ' donors)'];
        }
    }
    
    if ($pledgeAmount == 0 && empty($d['pledge'])) {
        $donor['issues'][] = ['type' => 'danger', 'icon' => 'pound-sign', 'text' => 'No pledge amount recorded'];
        $donor['issue_level'] = 'danger';
    }
    
    if (strpos($pledgeStr, '+') !== false) {
        $donor['issues'][] = ['type' => 'info', 'icon' => 'calculator', 'text' => 'Combined pledge: ' . $pledgeStr];
    }
    
    if ($paidAmount > $pledgeAmount && $pledgeAmount > 0) {
        $donor['issues'][] = ['type' => 'warning', 'icon' => 'arrow-up', 'text' => 'Overpaid by £' . number_format($paidAmount - $pledgeAmount)];
        if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
    }
    
    if (!empty($d['notes'])) {
        if (stripos($d['notes'], "didn't answer") !== false) {
            $donor['issues'][] = ['type' => 'warning', 'icon' => 'phone-alt', 'text' => 'Did not answer phone'];
            if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
        }
        if (stripos($d['notes'], 'missing') !== false) {
            $donor['issues'][] = ['type' => 'warning', 'icon' => 'question-circle', 'text' => $d['notes']];
            if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
        }
        if (stripos($d['notes'], 'manchester') !== false || stripos($d['notes'], 'blackpool') !== false) {
            $donor['issues'][] = ['type' => 'info', 'icon' => 'map-marker-alt', 'text' => $d['notes']];
        }
        if (stripos($d['notes'], 'auction') !== false) {
            $donor['issues'][] = ['type' => 'info', 'icon' => 'gavel', 'text' => $d['notes']];
        }
    }
    
    if ($pledgeAmount >= 5000) {
        $donor['issues'][] = ['type' => 'warning', 'icon' => 'gem', 'text' => 'VIP - £' . number_format($pledgeAmount)];
        if ($donor['issue_level'] !== 'danger') $donor['issue_level'] = 'warning';
    } elseif ($pledgeAmount >= 1500) {
        $donor['issues'][] = ['type' => 'info', 'icon' => 'star', 'text' => 'High value: £' . number_format($pledgeAmount)];
    }
    
    $donors[] = $donor;
}

$donorsWithIssues = array_filter($donors, fn($d) => !empty($d['issues']));

usort($donorsWithIssues, function($a, $b) {
    $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
    $levelA = $order[$a['issue_level']] ?? 3;
    $levelB = $order[$b['issue_level']] ?? 3;
    if ($levelA !== $levelB) return $levelA <=> $levelB;
    return $a['no'] <=> $b['no'];
});

$dangerCount = count(array_filter($donorsWithIssues, fn($d) => $d['issue_level'] === 'danger'));
$warningCount = count(array_filter($donorsWithIssues, fn($d) => $d['issue_level'] === 'warning'));
$infoCount = count(array_filter($donorsWithIssues, fn($d) => $d['issue_level'] === 'info'));

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#1e4d5c">
    <meta name="robots" content="noindex, nofollow">
    <meta name="googlebot" content="noindex, nofollow">
    <title>Donor Review - Liverpool Abune Teklehaymanot</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Ethiopic:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* Church Brand Colors */
            --primary-blue: #0a6286;
            --primary-dark: #1e4d5c;
            --primary-gold: #e2ca18;
            --accent-gold: #ffd700;
            --text-white: #ffffff;
            --text-light: #e8f4f8;
            --bg-light: #f8fafc;
            
            /* Status Colors */
            --danger: #dc3545;
            --warning: #f39c12;
            --success: #28a745;
            --info: #0a6286;
            
            /* Backgrounds */
            --danger-bg: rgba(220, 53, 69, 0.1);
            --warning-bg: rgba(243, 156, 18, 0.1);
            --info-bg: rgba(10, 98, 134, 0.1);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Noto Sans Ethiopic', 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, var(--primary-dark) 0%, #2a6b7d 100%);
            min-height: 100vh;
            color: var(--text-white);
        }
        
        /* Header */
        .header {
            background: rgba(0,0,0,0.2);
            backdrop-filter: blur(10px);
            padding: 16px;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .header-content {
            max-width: 600px;
            margin: 0 auto;
            text-align: center;
        }
        
        .header h1 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--accent-gold);
            margin-bottom: 4px;
        }
        
        .header p {
            font-size: 0.8rem;
            color: var(--text-light);
            opacity: 0.9;
        }
        
        /* Stats Pills */
        .stats-bar {
            display: flex;
            gap: 10px;
            padding: 16px;
            max-width: 600px;
            margin: 0 auto;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .stat-pill {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.15);
        }
        
        .stat-pill.danger { border-color: var(--danger); color: #ff8a8a; }
        .stat-pill.warning { border-color: var(--warning); color: #ffd98a; }
        .stat-pill.info { border-color: var(--accent-gold); color: var(--accent-gold); }
        
        .stat-pill .count {
            background: rgba(255,255,255,0.2);
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
        }
        
        .filter-tab {
            padding: 8px 16px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.2);
            background: transparent;
            color: var(--text-light);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
        }
        
        .filter-tab.active {
            background: linear-gradient(135deg, var(--primary-gold), #ffed4e);
            color: var(--primary-dark);
            border-color: var(--primary-gold);
        }
        
        .filter-tab:hover:not(.active) {
            background: rgba(255,255,255,0.1);
        }
        
        /* Main */
        .main {
            padding: 0 16px 100px;
            max-width: 600px;
            margin: 0 auto;
        }
        
        /* Donor Card */
        .donor-card {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            margin-bottom: 12px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .donor-card.danger { border-left: 4px solid var(--danger); }
        .donor-card.warning { border-left: 4px solid var(--warning); }
        .donor-card.info { border-left: 4px solid var(--accent-gold); }
        
        .donor-header {
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }
        
        .donor-info { flex: 1; min-width: 0; }
        
        .donor-number {
            font-size: 0.65rem;
            font-weight: 600;
            color: var(--accent-gold);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        
        .donor-name {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-white);
            margin-bottom: 4px;
        }
        
        .donor-phone {
            font-size: 0.8rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .donor-phone a {
            color: var(--accent-gold);
            text-decoration: none;
        }
        
        .donor-phone.error { color: #ff8a8a; }
        
        .call-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--success);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-decoration: none;
            margin-left: 8px;
        }
        
        .donor-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .donor-badge.danger { background: var(--danger-bg); color: var(--danger); }
        .donor-badge.warning { background: var(--warning-bg); color: var(--warning); }
        .donor-badge.info { background: var(--info-bg); color: var(--accent-gold); }
        
        /* Financial Row */
        .financial-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .financial-item {
            padding: 10px;
            text-align: center;
            border-right: 1px solid rgba(255,255,255,0.05);
        }
        
        .financial-item:last-child { border-right: none; }
        
        .financial-label {
            font-size: 0.6rem;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        
        .financial-value {
            font-size: 0.9rem;
            font-weight: 700;
        }
        
        .financial-value.pledge { color: var(--accent-gold); }
        .financial-value.paid { color: var(--success); }
        .financial-value.balance { color: var(--warning); }
        .financial-value.zero { color: rgba(255,255,255,0.4); }
        
        /* Issues */
        .issues-list {
            padding: 10px 14px 14px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .issues-title {
            font-size: 0.65rem;
            color: rgba(255,255,255,0.6);
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        
        .issue-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 8px 10px;
            border-radius: 8px;
            margin-bottom: 6px;
            font-size: 0.8rem;
        }
        
        .issue-item:last-child { margin-bottom: 0; }
        
        .issue-item.danger { background: var(--danger-bg); color: #ff8a8a; }
        .issue-item.warning { background: var(--warning-bg); color: #ffd98a; }
        .issue-item.info { background: var(--info-bg); color: var(--text-light); }
        
        .issue-item i { width: 14px; margin-top: 2px; flex-shrink: 0; }
        .issue-item.danger i { color: var(--danger); }
        .issue-item.warning i { color: var(--warning); }
        .issue-item.info i { color: var(--accent-gold); }
        
        /* Footer */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, var(--primary-dark), transparent);
            padding: 30px 16px 16px;
        }
        
        .footer-content {
            max-width: 600px;
            margin: 0 auto;
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        
        .footer-btn {
            padding: 10px 20px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .footer-btn.primary {
            background: linear-gradient(135deg, var(--primary-gold), #ffed4e);
            color: var(--primary-dark);
        }
        
        .footer-btn.secondary {
            background: rgba(255,255,255,0.1);
            color: var(--text-white);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .donor-card.hidden { display: none; }
        
        /* Empty */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
        }
        
        .empty-state i {
            font-size: 2.5rem;
            color: var(--success);
            margin-bottom: 12px;
        }
        
        .empty-state h3 {
            color: var(--accent-gold);
            margin-bottom: 6px;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1><i class="fas fa-clipboard-check"></i> Donor Review Report</h1>
            <p><?php echo count($donorsWithIssues); ?> of <?php echo count($donors); ?> donors need attention • <?php echo date('j M Y'); ?></p>
        </div>
    </header>
    
    <div class="stats-bar">
        <div class="stat-pill danger">
            <i class="fas fa-exclamation-circle"></i> Critical
            <span class="count"><?php echo $dangerCount; ?></span>
        </div>
        <div class="stat-pill warning">
            <i class="fas fa-exclamation-triangle"></i> Warning
            <span class="count"><?php echo $warningCount; ?></span>
        </div>
        <div class="stat-pill info">
            <i class="fas fa-info-circle"></i> Info
            <span class="count"><?php echo $infoCount; ?></span>
        </div>
    </div>
    
    <div class="filter-tabs">
        <button class="filter-tab active" data-filter="all">All (<?php echo count($donorsWithIssues); ?>)</button>
        <button class="filter-tab" data-filter="danger">Critical</button>
        <button class="filter-tab" data-filter="warning">Warning</button>
        <button class="filter-tab" data-filter="info">Info</button>
    </div>
    
    <main class="main">
        <?php if (empty($donorsWithIssues)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h3>All Clear!</h3>
                <p>No donors require attention.</p>
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
                <div class="donor-card <?php echo h($donor['issue_level']); ?>" data-level="<?php echo h($donor['issue_level']); ?>">
                    <div class="donor-header">
                        <div class="donor-info">
                            <div class="donor-number">#<?php echo $donor['no']; ?></div>
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
                                    <i class="fas fa-phone-slash"></i> No phone
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="donor-badge <?php echo h($donor['issue_level']); ?>">
                            <?php echo $donor['issue_level'] === 'danger' ? 'Critical' : ($donor['issue_level'] === 'warning' ? 'Warning' : 'Review'); ?>
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
                            <i class="fas fa-flag"></i> Issues (<?php echo count($donor['issues']); ?>)
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
            <button class="footer-btn secondary" onclick="window.scrollTo({top:0,behavior:'smooth'})">
                <i class="fas fa-arrow-up"></i> Top
            </button>
            <button class="footer-btn primary" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    </div>
    
    <script>
        document.querySelectorAll('.filter-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const filter = this.dataset.filter;
                document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                document.querySelectorAll('.donor-card').forEach(card => {
                    card.classList.toggle('hidden', filter !== 'all' && card.dataset.level !== filter);
                });
            });
        });
        
        let touchStart = 0;
        document.addEventListener('touchstart', e => touchStart = e.changedTouches[0].screenY);
        document.addEventListener('touchend', e => {
            if (window.scrollY === 0 && e.changedTouches[0].screenY - touchStart > 100) location.reload();
        });
    </script>
</body>
</html>
