<?php
/**
 * Donor Import Wizard
 * Step-by-step guide for importing donors from Excel into the system
 * 
 * Workflow:
 * 1. Register pledge on registrar page
 * 2. Approve pledge on admin approvals page
 * 3. Record payment (if paid) on record-pledge-payment page
 * 4. Approve payment on review-pledge-payments page
 * 5. Move to next donor
 */

// Donor data from Excel - parsed and cleaned
$donors = [
    // Donors 1-50 get reference 0601-0650
    // Donors 51+ get reference starting from 0452
    
    ['no' => 1, 'name' => 'Like Tiguhan Birhanu', 'phone' => '07473822244', 'pledge' => 1500, 'paid' => 500, 'method' => 'bank_transfer', 'notes' => 'paid £500', 'registrar' => 'T'],
    ['no' => 2, 'name' => 'Kesis Dagmawi', 'phone' => '07474962830', 'pledge' => 1500, 'paid' => 400, 'method' => 'bank_transfer', 'notes' => 'paid £400', 'registrar' => 'T'],
    ['no' => 3, 'name' => 'Woinshet t/ Medin', 'phone' => '07932793867', 'pledge' => 500, 'paid' => 400, 'method' => 'bank_transfer', 'notes' => 'paid £400', 'registrar' => 'T'],
    ['no' => 4, 'name' => 'Hiwot', 'phone' => '07508030686', 'pledge' => 1000, 'paid' => 250, 'method' => 'bank_transfer', 'notes' => 'paid £250', 'registrar' => 'T'],
    ['no' => 5, 'name' => 'Geda Gemechu', 'phone' => '07393180103', 'pledge' => 1000, 'paid' => 1000, 'method' => 'bank_transfer', 'notes' => 'paid all £1,000', 'registrar' => 'T'],
    ['no' => 6, 'name' => 'Mosisa Hunde', 'phone' => '07404411392', 'pledge' => 600, 'paid' => 0, 'method' => 'cash', 'notes' => '', 'registrar' => 'T'],
    ['no' => 7, 'name' => 'Ayelech Habtamu', 'phone' => '07435627896', 'pledge' => 1000, 'paid' => 1000, 'method' => 'cash', 'notes' => 'paid all £1,000', 'registrar' => 'T'],
    ['no' => 8, 'name' => 'Abel and Emuye', 'phone' => '07490447376', 'pledge' => 1000, 'paid' => 1000, 'method' => 'bank_transfer', 'notes' => 'paid all £1,000', 'registrar' => 'T'],
    ['no' => 9, 'name' => 'Yohanis Akililu', 'phone' => '07949146267', 'pledge' => 500, 'paid' => 500, 'method' => 'bank_transfer', 'notes' => 'paid all £500', 'registrar' => 'T'],
    ['no' => 10, 'name' => 'Nahom Alemu', 'phone' => '07915459008', 'pledge' => 500, 'paid' => 300, 'method' => 'bank_transfer', 'notes' => 'paid £300', 'registrar' => 'T'],
    ['no' => 11, 'name' => 'Sisay Asefa', 'phone' => '07482767756', 'pledge' => 1000, 'paid' => 700, 'method' => 'cash', 'notes' => 'paid £700', 'registrar' => 'T'],
    ['no' => 12, 'name' => 'Roza Hunde', 'phone' => '07902944713', 'pledge' => 1000, 'paid' => 1000, 'method' => 'cash', 'notes' => 'paid all £1,000', 'registrar' => 'T'],
    ['no' => 13, 'name' => 'Selam', 'phone' => '', 'pledge' => 200, 'paid' => 200, 'method' => 'cash', 'notes' => 'paid £200 - NO PHONE', 'registrar' => 'T'],
    ['no' => 14, 'name' => 'Yeshiwork', 'phone' => '07878567049', 'pledge' => 100, 'paid' => 200, 'method' => 'bank_transfer', 'notes' => 'paid all £200 (overpaid)', 'registrar' => 'T'],
    ['no' => 15, 'name' => 'Mekdes Tewolde', 'phone' => '07449884424', 'pledge' => 1000, 'paid' => 1000, 'method' => 'bank_transfer', 'notes' => 'paid all £1,000', 'registrar' => 'T'],
    ['no' => 16, 'name' => 'Mihret Birhanu', 'phone' => '07311305605', 'pledge' => 500, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'T'],
    ['no' => 17, 'name' => 'Etsub', 'phone' => '07931261431', 'pledge' => 1000, 'paid' => 500, 'method' => 'cash', 'notes' => 'paid £500', 'registrar' => 'J'],
    ['no' => 18, 'name' => 'Yalew Mekonnen', 'phone' => '07440347838', 'pledge' => 1000, 'paid' => 1000, 'method' => 'bank_transfer', 'notes' => 'paid all £1,000', 'registrar' => 'J'],
    ['no' => 19, 'name' => 'Yared Syoum', 'phone' => '07477732373', 'pledge' => 1500, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'J'],
    ['no' => 20, 'name' => 'Dereje Argaw', 'phone' => '07383333847', 'pledge' => 1000, 'paid' => 700, 'method' => 'bank_transfer', 'notes' => 'paid £700', 'registrar' => 'J'],
    ['no' => 21, 'name' => 'Kakidan Melkamu', 'phone' => '07311114440', 'pledge' => 500, 'paid' => 500, 'method' => 'bank_transfer', 'notes' => 'paid all £500', 'registrar' => 'J'],
    ['no' => 22, 'name' => 'Aster', 'phone' => '07508993242', 'pledge' => 0, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => 'NO PLEDGE AMOUNT - SKIP?', 'registrar' => 'J'],
    ['no' => 23, 'name' => 'Tesfaye Daba', 'phone' => '07944693263', 'pledge' => 100, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'J'],
    ['no' => 24, 'name' => 'Girma Birhan', 'phone' => '07873725678', 'pledge' => 300, 'paid' => 300, 'method' => 'bank_transfer', 'notes' => 'paid (assumed full)', 'registrar' => 'J'],
    ['no' => 25, 'name' => 'Gabreiel Mader', 'phone' => '07388418902', 'pledge' => 600, 'paid' => 100, 'method' => 'bank_transfer', 'notes' => 'paid £100', 'registrar' => 'J'],
    ['no' => 26, 'name' => 'Yonatan Dawit', 'phone' => '07828556674', 'pledge' => 50, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'J'],
    ['no' => 27, 'name' => 'Fiseha Habtamu', 'phone' => '07415217801', 'pledge' => 1000, 'paid' => 1000, 'method' => 'bank_transfer', 'notes' => 'paid all £1,000', 'registrar' => 'J'],
    ['no' => 28, 'name' => 'Eyerusalem and Tsegaye', 'phone' => '07719597801', 'pledge' => 500, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'J'],
    ['no' => 29, 'name' => 'Maranata Mehari', 'phone' => '07387173507', 'pledge' => 500, 'paid' => 500, 'method' => 'cash', 'notes' => 'paid all £500', 'registrar' => 'J'],
    ['no' => 30, 'name' => 'Henok Birhane', 'phone' => '07495039019', 'pledge' => 500, 'paid' => 0, 'method' => 'cash', 'notes' => '', 'registrar' => 'J'],
    ['no' => 31, 'name' => 'Helen Tewolde', 'phone' => '07378503752', 'pledge' => 500, 'paid' => 0, 'method' => 'cash', 'notes' => '', 'registrar' => 'J'],
    ['no' => 32, 'name' => 'Roza Awot', 'phone' => '07378503752', 'pledge' => 200, 'paid' => 0, 'method' => 'cash', 'notes' => 'From Manchester - DUPLICATE PHONE with #31', 'registrar' => 'E'],
    ['no' => 33, 'name' => 'Mulu Sate Mola', 'phone' => '07770075784', 'pledge' => 200, 'paid' => 0, 'method' => 'cash', 'notes' => '', 'registrar' => 'E'],
    ['no' => 34, 'name' => 'Meaza and Mahlet', 'phone' => '07438156695', 'pledge' => 750, 'paid' => 300, 'method' => 'cash', 'notes' => 'paid £300', 'registrar' => 'E'],
    ['no' => 35, 'name' => 'Hailemichael', 'phone' => '07455476714', 'pledge' => 500, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => 'Phone fixed (was 74554767141)', 'registrar' => 'E'],
    ['no' => 36, 'name' => 'Maya Mangistu', 'phone' => '07888139734', 'pledge' => 200, 'paid' => 200, 'method' => 'cash', 'notes' => 'paid all £200', 'registrar' => 'E'],
    ['no' => 37, 'name' => 'Saba Mekonen', 'phone' => '', 'pledge' => 300, 'paid' => 300, 'method' => 'cash', 'notes' => 'paid all £300 - NO PHONE', 'registrar' => 'E'],
    ['no' => 38, 'name' => 'Michael Nigusie', 'phone' => '07415329333', 'pledge' => 1000, 'paid' => 0, 'method' => 'cash', 'notes' => '', 'registrar' => 'E'],
    ['no' => 39, 'name' => 'W/Michael', 'phone' => '', 'pledge' => 35, 'paid' => 35, 'method' => 'cash', 'notes' => 'paid all £35 - NO PHONE', 'registrar' => 'E'],
    ['no' => 40, 'name' => 'Samuel', 'phone' => '07453303053', 'pledge' => 1000, 'paid' => 0, 'method' => 'cash', 'notes' => '', 'registrar' => 'E'],
    ['no' => 41, 'name' => 'Beti', 'phone' => '', 'pledge' => 110, 'paid' => 110, 'method' => 'cash', 'notes' => 'paid - NO PHONE', 'registrar' => 'E'],
    ['no' => 42, 'name' => 'Abel', 'phone' => '07360436171', 'pledge' => 500, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => "didn't answer", 'registrar' => 'E'],
    ['no' => 43, 'name' => 'Saniat', 'phone' => '07932793867', 'pledge' => 200, 'paid' => 0, 'method' => 'cash', 'notes' => 'C/o phone - DUPLICATE with #3', 'registrar' => 'E'],
    ['no' => 44, 'name' => 'Milana Birhane', 'phone' => '07359577270', 'pledge' => 500, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => 'Phone had missing digit - added 0', 'registrar' => 'E'],
    ['no' => 45, 'name' => 'Elsabeth Mitiku', 'phone' => '07365938258', 'pledge' => 300, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'E'],
    ['no' => 46, 'name' => 'Ermias Tekalu', 'phone' => '07415005376', 'pledge' => 500, 'paid' => 500, 'method' => 'bank_transfer', 'notes' => 'paid all £500', 'registrar' => 'E'],
    ['no' => 47, 'name' => 'Mikael Tesfaye', 'phone' => '07476336051', 'pledge' => 1000, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'J'],
    ['no' => 48, 'name' => 'Megabe Hadis Daniel', 'phone' => '07401399936', 'pledge' => 300, 'paid' => 150, 'method' => 'bank_transfer', 'notes' => 'paid £150', 'registrar' => 'J'],
    ['no' => 49, 'name' => 'Filmon Tedros', 'phone' => '07460485935', 'pledge' => 500, 'paid' => 500, 'method' => 'bank_transfer', 'notes' => 'paid all £500 - C/o Rahel', 'registrar' => 'J'],
    ['no' => 50, 'name' => 'Kbreab (Welde Gebreal)', 'phone' => '07459259509', 'pledge' => 1000, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'J'],
    // Donors 51+ get reference starting from 0452
    ['no' => 51, 'name' => 'Hagos Tsegaye', 'phone' => '07868671227', 'pledge' => 1000, 'paid' => 200, 'method' => 'cash', 'notes' => 'paid £200', 'registrar' => 'J'],
    ['no' => 52, 'name' => 'Lidya Hagos', 'phone' => '07706085397', 'pledge' => 250, 'paid' => 250, 'method' => 'bank_transfer', 'notes' => 'paid all £250', 'registrar' => 'E'],
    ['no' => 53, 'name' => 'Fqrte Gebrel', 'phone' => '07933293944', 'pledge' => 500, 'paid' => 500, 'method' => 'bank_transfer', 'notes' => 'paid all £500', 'registrar' => 'J'],
    ['no' => 54, 'name' => 'Genet Solomon', 'phone' => '07931796244', 'pledge' => 100, 'paid' => 100, 'method' => 'bank_transfer', 'notes' => 'paid all £100', 'registrar' => 'J'],
    ['no' => 55, 'name' => 'Filmon G/ezgi', 'phone' => '07476743908', 'pledge' => 500, 'paid' => 500, 'method' => 'bank_transfer', 'notes' => 'paid all £500', 'registrar' => 'J'],
    ['no' => 56, 'name' => 'Mahilet Hagos', 'phone' => '07438253791', 'pledge' => 300, 'paid' => 300, 'method' => 'bank_transfer', 'notes' => 'paid all £300', 'registrar' => 'J'],
    ['no' => 57, 'name' => 'Yared Habtemaryam', 'phone' => '07392205538', 'pledge' => 100, 'paid' => 100, 'method' => 'bank_transfer', 'notes' => 'paid all £100', 'registrar' => 'J'],
    ['no' => 58, 'name' => 'Eyobe Zelalem', 'phone' => '07466690312', 'pledge' => 600, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'T'],
    ['no' => 59, 'name' => 'Kibrom Getchew', 'phone' => '07495760372', 'pledge' => 600, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'T'],
    ['no' => 60, 'name' => 'H/mariam Tesfe', 'phone' => '07469481854', 'pledge' => 600, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'T'],
    ['no' => 61, 'name' => 'Yontan', 'phone' => '07516172076', 'pledge' => 1000, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'T'],
    ['no' => 62, 'name' => 'Mesfin Tefera (Blackpool)', 'phone' => '07386208291', 'pledge' => 600, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'T'],
    ['no' => 63, 'name' => 'Amanuel', 'phone' => '07392364310', 'pledge' => 500, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'T'],
    ['no' => 64, 'name' => 'Kiflemicheal (Henok)', 'phone' => '07411002386', 'pledge' => 500, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'T'],
    ['no' => 65, 'name' => 'Ashenafi Bereda', 'phone' => '07739440766', 'pledge' => 500, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'T'],
    ['no' => 66, 'name' => 'Tewodros Akililu', 'phone' => '07456574276', 'pledge' => 500, 'paid' => 250, 'method' => 'bank_transfer', 'notes' => 'paid £250', 'registrar' => 'T'],
    ['no' => 67, 'name' => 'Jemla Sefa', 'phone' => '07413117896', 'pledge' => 600, 'paid' => 600, 'method' => 'bank_transfer', 'notes' => 'paid all £600', 'registrar' => 'T'],
    ['no' => 68, 'name' => 'Saba Mekonnen', 'phone' => '07727346626', 'pledge' => 300, 'paid' => 300, 'method' => 'bank_transfer', 'notes' => 'paid all £300', 'registrar' => 'E'],
    ['no' => 69, 'name' => 'Mesert H/selasie (Grace)', 'phone' => '07500657641', 'pledge' => 5000, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => 'LARGEST PLEDGE', 'registrar' => 'E'],
    ['no' => 70, 'name' => 'Abebeau Abera', 'phone' => '07513816289', 'pledge' => 1000, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'E'],
    ['no' => 71, 'name' => 'Daniel Mesfin', 'phone' => '07455805157', 'pledge' => 1000, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'E'],
    ['no' => 72, 'name' => 'Yared Kidane', 'phone' => '07307718126', 'pledge' => 1000, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'E'],
    ['no' => 73, 'name' => 'Haile Alemu', 'phone' => '07857223571', 'pledge' => 900, 'paid' => 100, 'method' => 'bank_transfer', 'notes' => 'paid £100', 'registrar' => 'E'],
    ['no' => 74, 'name' => 'Dejene', 'phone' => '07449212748', 'pledge' => 1000, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'E'],
    ['no' => 75, 'name' => 'Betlehem Alemayehu (Getacheu)', 'phone' => '07476884024', 'pledge' => 500, 'paid' => 90, 'method' => 'bank_transfer', 'notes' => 'paid £90', 'registrar' => 'E'],
    ['no' => 76, 'name' => 'Selamawit Afeworkie', 'phone' => '07946869284', 'pledge' => 1100, 'paid' => 1100, 'method' => 'bank_transfer', 'notes' => 'paid all £1,100', 'registrar' => 'E'],
    ['no' => 77, 'name' => 'Haile Yesus (Barber)', 'phone' => '', 'pledge' => 150, 'paid' => 150, 'method' => 'cash', 'notes' => 'paid all £150 - NO PHONE', 'registrar' => 'E'],
    ['no' => 78, 'name' => 'Daniel Kassa', 'phone' => '', 'pledge' => 1000, 'paid' => 800, 'method' => 'cash', 'notes' => 'paid £800 - NO PHONE', 'registrar' => 'E'],
    ['no' => 79, 'name' => 'Tesfaye Mezmuran', 'phone' => '', 'pledge' => 500, 'paid' => 500, 'method' => 'cash', 'notes' => 'paid all £500 - NO PHONE', 'registrar' => 'E'],
    ['no' => 80, 'name' => 'Frehiwot', 'phone' => '07981670102', 'pledge' => 300, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'J'],
    ['no' => 81, 'name' => 'Elsa Tadesse', 'phone' => '07458985366', 'pledge' => 200, 'paid' => 50, 'method' => 'bank_transfer', 'notes' => 'paid £50', 'registrar' => 'J'],
    ['no' => 82, 'name' => 'Tesfanesh Megersa', 'phone' => '07479334292', 'pledge' => 100, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'J'],
    ['no' => 83, 'name' => 'Tegist Kassa', 'phone' => '07480933736', 'pledge' => 50, 'paid' => 50, 'method' => 'bank_transfer', 'notes' => 'paid all £50', 'registrar' => 'J'],
    ['no' => 84, 'name' => 'Woleteslassie', 'phone' => '07588152998', 'pledge' => 50, 'paid' => 20, 'method' => 'bank_transfer', 'notes' => 'paid £20', 'registrar' => 'J'],
    ['no' => 85, 'name' => 'Woletemariam', 'phone' => '07476103881', 'pledge' => 50, 'paid' => 10, 'method' => 'bank_transfer', 'notes' => 'paid £10', 'registrar' => 'J'],
    ['no' => 86, 'name' => 'Hanock Philemon', 'phone' => '07904936740', 'pledge' => 1000, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'J'],
    ['no' => 87, 'name' => 'Ababia Gemechu', 'phone' => '07749027431', 'pledge' => 1000, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => '', 'registrar' => 'J'],
    ['no' => 88, 'name' => 'Simon Yohanes', 'phone' => '07406034100', 'pledge' => 300, 'paid' => 300, 'method' => 'bank_transfer', 'notes' => 'paid all £300 - Phone fixed (added 0)', 'registrar' => 'J'],
    ['no' => 89, 'name' => 'Semhar Abrhame', 'phone' => '', 'pledge' => 600, 'paid' => 200, 'method' => 'cash', 'notes' => 'paid £200 - NO PHONE', 'registrar' => 'E'],
    ['no' => 90, 'name' => 'Tsehaye Getnet', 'phone' => '', 'pledge' => 200, 'paid' => 200, 'method' => 'cash', 'notes' => 'paid all £200 - NO PHONE', 'registrar' => 'E'],
    ['no' => 91, 'name' => 'Yonas Legese', 'phone' => '', 'pledge' => 1000, 'paid' => 1000, 'method' => 'cash', 'notes' => 'paid all £1,000 - NO PHONE', 'registrar' => 'E'],
    ['no' => 92, 'name' => 'Beza', 'phone' => '', 'pledge' => 100, 'paid' => 100, 'method' => 'bank_transfer', 'notes' => 'paid all £100 - NO PHONE', 'registrar' => 'E'],
    ['no' => 93, 'name' => 'Georgia & Muhammed', 'phone' => '', 'pledge' => 100, 'paid' => 100, 'method' => 'cash', 'notes' => 'paid all £100 - NO PHONE', 'registrar' => 'E'],
    ['no' => 94, 'name' => 'Eden Mehari', 'phone' => '07961474962', 'pledge' => 100, 'paid' => 100, 'method' => 'cash', 'notes' => 'paid £100 - pledge amount assumed', 'registrar' => 'E'],
    ['no' => 95, 'name' => 'Eyarusalem Hagos', 'phone' => '07951545098', 'pledge' => 700, 'paid' => 700, 'method' => 'cash', 'notes' => 'paid all £700 (500+200)', 'registrar' => 'T'],
    ['no' => 96, 'name' => 'Tewodros Ferewe', 'phone' => '07480973939', 'pledge' => 0, 'paid' => 0, 'method' => 'bank_transfer', 'notes' => 'NO PLEDGE AMOUNT - SKIP?', 'registrar' => 'T'],
];

// Generate reference numbers
function getReference($donorNo) {
    if ($donorNo <= 50) {
        return str_pad(600 + $donorNo, 4, '0', STR_PAD_LEFT); // 0601-0650
    } else {
        return str_pad(451 + ($donorNo - 50), 4, '0', STR_PAD_LEFT); // 0452, 0453, ...
    }
}

// Add reference to each donor
foreach ($donors as $i => &$donor) {
    $donor['reference'] = getReference($donor['no']);
    $donor['balance'] = $donor['pledge'] - $donor['paid'];
    $donor['status'] = $donor['paid'] >= $donor['pledge'] && $donor['pledge'] > 0 ? 'completed' : 
                       ($donor['paid'] > 0 ? 'paying' : 'not_started');
    $donor['has_payment'] = $donor['paid'] > 0;
}
unset($donor);

// Calculate totals
$totalPledged = array_sum(array_column($donors, 'pledge'));
$totalPaid = array_sum(array_column($donors, 'paid'));
$totalBalance = $totalPledged - $totalPaid;
$completedCount = count(array_filter($donors, fn($d) => $d['status'] === 'completed'));
$payingCount = count(array_filter($donors, fn($d) => $d['status'] === 'paying'));
$notStartedCount = count(array_filter($donors, fn($d) => $d['status'] === 'not_started'));

// Get current donor index from session or default to 0
session_start();
$currentIndex = isset($_GET['donor']) ? max(0, min((int)$_GET['donor'] - 1, count($donors) - 1)) : ($_SESSION['current_donor_index'] ?? 0);
$_SESSION['current_donor_index'] = $currentIndex;
$currentDonor = $donors[$currentIndex];

// Get payment method display name
function getPaymentMethodDisplay($method) {
    $methods = [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'card' => 'Card'
    ];
    return $methods[$method] ?? 'Bank Transfer';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor Import Wizard - Step by Step</title>
    <link rel="icon" type="image/svg+xml" href="../../assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #f8fafc;
            --bg-secondary: #ffffff;
            --bg-tertiary: #f1f5f9;
            --bg-card: #ffffff;
            --bg-hover: #e2e8f0;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border: #e2e8f0;
            --border-hover: #cbd5e1;
            --accent-blue: #3b82f6;
            --accent-purple: #8b5cf6;
            --accent-green: #10b981;
            --accent-orange: #f59e0b;
            --accent-red: #ef4444;
            --accent-cyan: #0891b2;
            --accent-indigo: #6366f1;
            --glow-blue: rgba(59, 130, 246, 0.2);
            --glow-green: rgba(16, 185, 129, 0.2);
            --glow-purple: rgba(139, 92, 246, 0.2);
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Space Grotesk', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-primary);
            background-image: 
                radial-gradient(ellipse at top left, rgba(59, 130, 246, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at bottom right, rgba(139, 92, 246, 0.08) 0%, transparent 50%);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.6;
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: var(--bg-tertiary);
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        .wizard-header {
            background: linear-gradient(135deg, #1e40af 0%, #7c3aed 100%);
            border-bottom: none;
            padding: 24px 0;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-lg);
        }
        
        .progress-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .wizard-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #ffffff;
        }
        
        .wizard-header p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.875rem;
        }
        
        .donor-nav select {
            padding: 10px 16px;
            border-radius: 10px;
            border: none;
            background: rgba(255, 255, 255, 0.15);
            color: #ffffff;
            font-size: 14px;
            font-family: inherit;
            cursor: pointer;
            min-width: 250px;
            transition: all 0.2s;
        }
        
        .donor-nav select option {
            background: var(--bg-card);
            color: var(--text-primary);
        }
        
        .donor-nav select:hover {
            background: rgba(255, 255, 255, 0.25);
        }
        
        .donor-nav select:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.25);
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.2);
        }
        
        .stats-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .stat-item {
            background: rgba(255, 255, 255, 0.15);
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stat-item i {
            font-size: 12px;
        }
        
        /* Donor Card */
        .donor-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }
        
        .donor-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6, #ec4899, #f59e0b);
            background-size: 300% 100%;
            animation: gradientMove 4s ease infinite;
        }
        
        @keyframes gradientMove {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        .donor-name {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        
        .donor-meta {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        
        .donor-meta-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: #475569;
            background: #f1f5f9;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        
        .donor-meta-item i {
            color: #3b82f6;
            font-size: 12px;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-completed {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }
        
        .status-paying {
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }
        
        .status-not_started {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        
        /* Financial Summary */
        .financial-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 20px;
        }
        
        .financial-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            transition: all 0.2s;
        }
        
        .financial-item:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .financial-item .amount {
            font-size: 1.5rem;
            font-weight: 700;
            font-family: 'JetBrains Mono', monospace;
            color: #1e293b;
        }
        
        .financial-item .amount.text-success { color: #047857; }
        .financial-item .amount.text-danger { color: #dc2626; }
        
        .financial-item .label {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 6px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* Step Containers */
        .step-container {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 16px;
            overflow: hidden;
            transition: all 0.3s;
            box-shadow: var(--shadow-sm);
        }
        
        .step-container:hover {
            border-color: var(--border-hover);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        
        .step-header {
            padding: 20px 24px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 15px;
        }
        
        .step-number {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            margin-right: 14px;
            color: #ffffff;
        }
        
        .step-1 .step-number { background: #3b82f6; }
        .step-2 .step-number { background: #f59e0b; }
        .step-3 .step-number { background: #10b981; }
        .step-4 .step-number { background: #8b5cf6; }
        
        .step-header.step-1 {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            color: #1e40af;
            border-bottom: 1px solid #bfdbfe;
        }
        
        .step-header.step-2 {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            color: #b45309;
            border-bottom: 1px solid #fde68a;
        }
        
        .step-header.step-3 {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            color: #047857;
            border-bottom: 1px solid #a7f3d0;
        }
        
        .step-header.step-4 {
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
            color: #6d28d9;
            border-bottom: 1px solid #ddd6fe;
        }
        
        .step-content {
            padding: 24px;
            background: var(--bg-card);
        }
        
        .step-content p {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }
        
        /* Copy Fields */
        .copy-field {
            display: flex;
            align-items: center;
            margin-bottom: 14px;
            gap: 12px;
        }
        
        .copy-field label {
            min-width: 150px;
            font-weight: 500;
            color: var(--text-secondary);
            font-size: 13px;
        }
        
        .copy-field .value {
            flex: 1;
            padding: 12px 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            color: #1e293b;
            transition: all 0.2s;
        }
        
        .copy-field .value:hover {
            border-color: #3b82f6;
            background: #ffffff;
        }
        
        .copy-btn {
            padding: 12px 20px;
            background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);
        }
        
        .copy-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }
        
        .copy-btn.copied {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        
        /* External Links */
        .external-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            background: linear-gradient(135deg, #1e40af 0%, #7c3aed 100%);
            border: none;
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.2s;
            box-shadow: 0 2px 4px rgba(30, 64, 175, 0.3);
        }
        
        .external-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(30, 64, 175, 0.4);
            color: white;
        }
        
        /* Warning Box */
        .warning-box {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            color: #b45309;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .warning-box i {
            font-size: 18px;
            color: #f59e0b;
        }
        
        /* Skip Payment */
        .skip-payment {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 32px;
            text-align: center;
            color: #94a3b8;
        }
        
        .skip-payment i {
            color: #cbd5e1;
        }
        
        /* Instructions Box */
        .instructions-box {
            background: linear-gradient(135deg, #ecfeff 0%, #cffafe 100%);
            border: 1px solid #a5f3fc;
            border-left: 4px solid #0891b2;
            border-radius: 10px;
            padding: 16px 20px;
            margin-top: 20px;
        }
        
        .instructions-box strong {
            color: #0e7490;
            display: block;
            margin-bottom: 8px;
        }
        
        .instructions-box ol {
            margin: 12px 0 0 20px;
            color: #164e63;
        }
        
        .instructions-box ol li {
            margin-bottom: 8px;
            padding-left: 4px;
        }
        
        .instructions-box ol li strong {
            color: #0e7490;
            display: inline;
            margin-bottom: 0;
        }
        
        /* Navigation Buttons */
        .nav-buttons {
            display: flex;
            gap: 12px;
            justify-content: space-between;
            margin-top: 32px;
            padding: 24px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-nav {
            padding: 14px 32px;
            border-radius: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn-outline-secondary {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #64748b;
        }
        
        .btn-outline-secondary:hover {
            background: #e2e8f0;
            color: #475569;
            border-color: #cbd5e1;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%);
            color: white;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }
        
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        /* Checklist */
        .checklist {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .checklist li {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-radius: 8px;
            margin-bottom: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #64748b;
            transition: all 0.2s;
        }
        
        .checklist li i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }
        
        .checklist li.done {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }
        
        .checklist li.done i {
            color: #10b981;
        }
        
        .checklist li.text-muted {
            opacity: 0.5;
        }
        
        /* Sidebar Toggle */
        .sidebar-toggle {
            position: fixed;
            right: 24px;
            bottom: 24px;
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: linear-gradient(135deg, #1e40af 0%, #7c3aed 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 20px rgba(30, 64, 175, 0.4);
            cursor: pointer;
            font-size: 20px;
            z-index: 1001;
            transition: all 0.3s;
        }
        
        .sidebar-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 8px 30px rgba(124, 58, 237, 0.5);
        }
        
        /* Donor List Sidebar */
        .donor-list-sidebar {
            position: fixed;
            right: 0;
            top: 0;
            bottom: 0;
            width: 320px;
            background: #ffffff;
            border-left: 1px solid #e2e8f0;
            overflow-y: auto;
            transform: translateX(100%);
            transition: transform 0.3s ease;
            z-index: 1000;
        }
        
        .donor-list-sidebar.open {
            transform: translateX(0);
            box-shadow: -10px 0 40px rgba(0, 0, 0, 0.15);
        }
        
        .sidebar-header {
            background: linear-gradient(135deg, #1e40af 0%, #7c3aed 100%);
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .sidebar-header h5 {
            color: #ffffff;
            font-weight: 600;
            margin: 0;
            font-size: 16px;
        }
        
        .sidebar-header button {
            color: rgba(255, 255, 255, 0.8) !important;
        }
        
        .sidebar-header button:hover {
            color: #ffffff !important;
        }
        
        .donor-list-item {
            padding: 14px 18px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: all 0.2s;
            background: transparent;
        }
        
        .donor-list-item:hover {
            background: #f8fafc;
        }
        
        .donor-list-item.active {
            background: #eff6ff;
            border-left: 3px solid #3b82f6;
        }
        
        .donor-list-item.completed {
            background: #f0fdf4;
        }
        
        .donor-list-item strong {
            color: #1e293b;
        }
        
        .donor-list-item small {
            color: #94a3b8;
        }
        
        /* Notes area */
        .mt-3.p-2.bg-white {
            background: var(--bg-tertiary) !important;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 16px !important;
        }
        
        .mt-3.p-2.bg-white small {
            color: var(--text-secondary);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .copy-field {
                flex-direction: column;
                align-items: flex-start;
            }
            .copy-field label {
                min-width: auto;
            }
            .copy-field .value {
                width: 100%;
            }
            .financial-summary {
                grid-template-columns: 1fr;
            }
            .nav-buttons {
                flex-direction: column;
            }
            .btn-nav {
                width: 100%;
                justify-content: center;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .step-container {
            animation: fadeIn 0.3s ease;
        }
        
        .donor-card {
            animation: fadeIn 0.4s ease;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="wizard-header">
        <div class="container">
            <div class="progress-info">
                <div>
                    <h1 class="h4 mb-1"><i class="fas fa-magic me-2"></i>Donor Import Wizard</h1>
                    <p class="mb-0 opacity-75">Processing donor <?php echo $currentIndex + 1; ?> of <?php echo count($donors); ?></p>
                </div>
                <div class="stats-bar">
                    <div class="stat-item">
                        <i class="fas fa-users me-1"></i> <?php echo count($donors); ?> Total
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-check-circle me-1"></i> <?php echo $completedCount; ?> Completed
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-spinner me-1"></i> <?php echo $payingCount; ?> Paying
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-clock me-1"></i> <?php echo $notStartedCount; ?> Not Started
                    </div>
                </div>
                <div class="donor-nav">
                    <select id="donorSelect" class="form-select" onchange="goToDonor(this.value)">
                        <?php foreach ($donors as $i => $d): ?>
                        <option value="<?php echo $i + 1; ?>" <?php echo $i === $currentIndex ? 'selected' : ''; ?>>
                            #<?php echo $d['no']; ?> - <?php echo htmlspecialchars($d['name']); ?>
                            <?php echo $d['status'] === 'completed' ? '✓' : ''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="container pb-5">
        <!-- Current Donor Card -->
        <div class="donor-card">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <div class="donor-name">
                        #<?php echo $currentDonor['no']; ?> - <?php echo htmlspecialchars($currentDonor['name']); ?>
                    </div>
                    <span class="status-badge status-<?php echo $currentDonor['status']; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $currentDonor['status'])); ?>
                    </span>
                    <div class="donor-meta">
                        <div class="donor-meta-item">
                            <i class="fas fa-phone"></i>
                            <?php echo $currentDonor['phone'] ?: 'NO PHONE'; ?>
                        </div>
                        <div class="donor-meta-item">
                            <i class="fas fa-hashtag"></i>
                            Ref: <?php echo $currentDonor['reference']; ?>
                        </div>
                        <div class="donor-meta-item">
                            <i class="fas fa-user-tag"></i>
                            Registrar: <?php echo $currentDonor['registrar']; ?>
                        </div>
                    </div>
                </div>
                <div class="financial-summary">
                    <div class="financial-item">
                        <div class="amount">£<?php echo number_format($currentDonor['pledge'], 2); ?></div>
                        <div class="label">Pledged</div>
                    </div>
                    <div class="financial-item">
                        <div class="amount text-success">£<?php echo number_format($currentDonor['paid'], 2); ?></div>
                        <div class="label">Paid</div>
                    </div>
                    <div class="financial-item">
                        <div class="amount text-danger">£<?php echo number_format($currentDonor['balance'], 2); ?></div>
                        <div class="label">Balance</div>
                    </div>
                </div>
            </div>
            <?php if ($currentDonor['notes']): ?>
            <div class="notes-box" style="margin-top: 16px; padding: 12px 16px; background: #fefce8; border: 1px solid #fde68a; border-radius: 10px;">
                <small style="color: #854d0e;"><i class="fas fa-sticky-note me-2" style="color: #eab308;"></i><?php echo htmlspecialchars($currentDonor['notes']); ?></small>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!$currentDonor['phone']): ?>
        <div class="warning-box">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Warning:</strong> This donor has no phone number. You may need to create a placeholder phone or skip this donor.
        </div>
        <?php endif; ?>

        <?php if ($currentDonor['pledge'] <= 0): ?>
        <div class="warning-box">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Warning:</strong> This donor has no pledge amount. Consider skipping this donor.
        </div>
        <?php endif; ?>

        <!-- Step 1: Registration -->
        <div class="step-container">
            <div class="step-header step-1" onclick="toggleStep(1)">
                <span><span class="step-number">1</span> Register Pledge</span>
                <a href="https://donate.abuneteklehaymanot.org/registrar/" target="_blank" class="external-link" onclick="event.stopPropagation();">
                    <i class="fas fa-external-link-alt"></i> Open Registrar
                </a>
            </div>
            <div class="step-content" id="step1">
                <div class="row g-2">
                    <div class="col-md-6">
                        <div class="copy-field mb-2">
                            <label>Name:</label>
                            <div class="value" id="field-name"><?php echo htmlspecialchars($currentDonor['name']); ?></div>
                            <button class="copy-btn" onclick="copyField('field-name', this)"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="copy-field mb-2">
                            <label>Phone:</label>
                            <div class="value" id="field-phone"><?php echo $currentDonor['phone'] ?: 'NO PHONE'; ?></div>
                            <button class="copy-btn" onclick="copyField('field-phone', this)"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="copy-field mb-2">
                            <label>Tombola:</label>
                            <div class="value" id="field-tombola"><?php echo $currentDonor['reference']; ?></div>
                            <button class="copy-btn" onclick="copyField('field-tombola', this)"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="copy-field mb-2">
                            <label>Amount:</label>
                            <div class="value" id="field-amount"><?php echo $currentDonor['pledge']; ?></div>
                            <button class="copy-btn" onclick="copyField('field-amount', this)"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                </div>
                <div class="mt-3 p-2 rounded text-center" style="background: #dbeafe; color: #1e40af; font-size: 13px;">
                    <i class="fas fa-info-circle me-1"></i> Select <strong>Custom</strong> → Enter amount → <strong>Promise to Pay Later</strong>
                </div>
            </div>
        </div>

        <!-- Step 2: Approval -->
        <div class="step-container">
            <div class="step-header step-2" onclick="toggleStep(2)">
                <span><span class="step-number">2</span> Approve Pledge</span>
                <a href="https://donate.abuneteklehaymanot.org/admin/approvals/" target="_blank" class="external-link" onclick="event.stopPropagation();">
                    <i class="fas fa-external-link-alt"></i> Open Approvals
                </a>
            </div>
            <div class="step-content" id="step2">
                <div class="d-flex align-items-center justify-content-between p-3 rounded" style="background: #f0fdf4; border: 1px solid #bbf7d0;">
                    <div>
                        <strong style="color: #166534;"><?php echo htmlspecialchars($currentDonor['name']); ?></strong>
                        <span style="color: #15803d;"> — £<?php echo number_format($currentDonor['pledge'], 2); ?></span>
                    </div>
                    <span style="color: #16a34a;"><i class="fas fa-arrow-right me-2"></i>Click Approve</span>
                </div>
            </div>
        </div>

        <!-- Step 3: Record Payment (if paid) -->
        <div class="step-container">
            <div class="step-header step-3" onclick="toggleStep(3)">
                <span><span class="step-number">3</span> Record Payment</span>
                <?php if ($currentDonor['has_payment']): ?>
                <a href="https://donate.abuneteklehaymanot.org/admin/donations/record-pledge-payment.php" target="_blank" class="external-link" onclick="event.stopPropagation();">
                    <i class="fas fa-external-link-alt"></i> Open Payment Form
                </a>
                <?php endif; ?>
            </div>
            <div class="step-content" id="step3">
                <?php if ($currentDonor['has_payment']): ?>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="copy-field mb-0">
                            <label>Amount:</label>
                            <div class="value" id="field-pay-amount"><?php echo $currentDonor['paid']; ?></div>
                            <button class="copy-btn" onclick="copyField('field-pay-amount', this)"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="copy-field mb-0">
                            <label>Reference:</label>
                            <div class="value" id="field-pay-ref"><?php echo $currentDonor['reference']; ?></div>
                            <button class="copy-btn" onclick="copyField('field-pay-ref', this)"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                </div>
                <div class="mt-3 p-3 rounded" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                    <span style="color: #64748b;">Method: <strong style="color: #1e293b;"><?php echo getPaymentMethodDisplay($currentDonor['method']); ?></strong></span>
                </div>
                <?php else: ?>
                <div class="skip-payment">
                    <i class="fas fa-forward fa-2x mb-3 d-block"></i>
                    <strong>No Payment</strong>
                    <p class="mb-0">Skip to next donor</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Step 4: Approve Payment (if paid) -->
        <div class="step-container">
            <div class="step-header step-4" onclick="toggleStep(4)">
                <span><span class="step-number">4</span> Approve Payment</span>
                <?php if ($currentDonor['has_payment']): ?>
                <a href="https://donate.abuneteklehaymanot.org/admin/donations/review-pledge-payments.php" target="_blank" class="external-link" onclick="event.stopPropagation();">
                    <i class="fas fa-external-link-alt"></i> Open Payment Review
                </a>
                <?php endif; ?>
            </div>
            <div class="step-content" id="step4">
                <?php if ($currentDonor['has_payment']): ?>
                <div class="d-flex align-items-center justify-content-between p-3 rounded" style="background: #f5f3ff; border: 1px solid #ddd6fe;">
                    <div>
                        <strong style="color: #5b21b6;"><?php echo htmlspecialchars($currentDonor['name']); ?></strong>
                        <span style="color: #7c3aed;"> — £<?php echo number_format($currentDonor['paid'], 2); ?></span>
                    </div>
                    <span style="color: #8b5cf6;"><i class="fas fa-arrow-right me-2"></i>Click Approve</span>
                </div>
                <?php else: ?>
                <div class="skip-payment">
                    <i class="fas fa-forward fa-2x mb-3 d-block"></i>
                    <strong>No Payment</strong>
                    <p class="mb-0">Skip to next donor</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Checklist -->
        <div class="step-container">
            <div class="step-header" style="background: linear-gradient(135deg, #ecfeff 0%, #cffafe 100%); color: #0e7490; border-bottom: 1px solid #a5f3fc;">
                <span><i class="fas fa-clipboard-check me-2"></i> Completion Checklist</span>
            </div>
            <div class="step-content">
                <ul class="checklist">
                    <li id="check-1"><i class="far fa-square"></i> Step 1: Pledge Registered</li>
                    <li id="check-2"><i class="far fa-square"></i> Step 2: Pledge Approved</li>
                    <?php if ($currentDonor['has_payment']): ?>
                    <li id="check-3"><i class="far fa-square"></i> Step 3: Payment Recorded (£<?php echo number_format($currentDonor['paid'], 2); ?>)</li>
                    <li id="check-4"><i class="far fa-square"></i> Step 4: Payment Approved</li>
                    <?php else: ?>
                    <li id="check-3" class="text-muted"><i class="fas fa-minus"></i> Step 3: No Payment (skipped)</li>
                    <li id="check-4" class="text-muted"><i class="fas fa-minus"></i> Step 4: No Payment (skipped)</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Navigation -->
        <div class="nav-buttons">
            <div>
                <?php if ($currentIndex > 0): ?>
                <a href="?donor=<?php echo $currentIndex; ?>" class="btn btn-outline-secondary btn-nav">
                    <i class="fas fa-arrow-left"></i> Previous Donor
                </a>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-success btn-nav" onclick="markComplete()">
                    <i class="fas fa-check"></i> Mark Complete
                </button>
                <?php if ($currentIndex < count($donors) - 1): ?>
                <a href="?donor=<?php echo $currentIndex + 2; ?>" class="btn btn-primary btn-nav">
                    Next Donor <i class="fas fa-arrow-right"></i>
                </a>
                <?php else: ?>
                <button class="btn btn-primary btn-nav" disabled>
                    <i class="fas fa-flag-checkered"></i> All Done!
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sidebar Toggle -->
    <button class="sidebar-toggle" onclick="toggleSidebar()">
        <i class="fas fa-list"></i>
    </button>

    <!-- Donor List Sidebar -->
    <div class="donor-list-sidebar" id="donorSidebar">
        <div class="sidebar-header">
            <h5><i class="fas fa-users me-2"></i>All Donors</h5>
            <button onclick="toggleSidebar()" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 18px;"><i class="fas fa-times"></i></button>
        </div>
        <?php foreach ($donors as $i => $d): ?>
        <div class="donor-list-item <?php echo $i === $currentIndex ? 'active' : ''; ?>" 
             onclick="goToDonor(<?php echo $i + 1; ?>)">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong>#<?php echo $d['no']; ?></strong> <?php echo htmlspecialchars(substr($d['name'], 0, 20)); ?>
                </div>
                <span class="status-badge status-<?php echo $d['status']; ?>" style="font-size: 10px; padding: 2px 8px;">
                    <?php echo $d['status'] === 'completed' ? '✓' : ($d['status'] === 'paying' ? '…' : '○'); ?>
                </span>
            </div>
            <small class="text-muted">£<?php echo number_format($d['pledge']); ?> | Ref: <?php echo $d['reference']; ?></small>
        </div>
        <?php endforeach; ?>
    </div>

    <script>
        function copyField(fieldId, btn) {
            const field = document.getElementById(fieldId);
            const text = field.innerText;
            
            navigator.clipboard.writeText(text).then(() => {
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
                    btn.classList.remove('copied');
                }, 2000);
            }).catch(() => {
                // Fallback
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.innerHTML = '<i class="fas fa-copy"></i> Copy';
                    btn.classList.remove('copied');
                }, 2000);
            });
        }

        function toggleStep(stepNum) {
            const content = document.getElementById('step' + stepNum);
            content.style.display = content.style.display === 'none' ? 'block' : 'none';
        }

        function goToDonor(donorNum) {
            window.location.href = '?donor=' + donorNum;
        }

        function toggleSidebar() {
            document.getElementById('donorSidebar').classList.toggle('open');
        }

        function markComplete() {
            // Mark checklist items as done
            const hasPayment = <?php echo $currentDonor['has_payment'] ? 'true' : 'false'; ?>;
            
            document.querySelectorAll('.checklist li').forEach((li, index) => {
                if (hasPayment || index < 2) {
                    li.classList.add('done');
                    li.querySelector('i').className = 'fas fa-check-square text-success';
                }
            });
            
            // Save to localStorage
            const completed = JSON.parse(localStorage.getItem('importedDonors') || '[]');
            if (!completed.includes(<?php echo $currentDonor['no']; ?>)) {
                completed.push(<?php echo $currentDonor['no']; ?>);
                localStorage.setItem('importedDonors', JSON.stringify(completed));
            }
            
            // Auto-advance after 1 second
            setTimeout(() => {
                <?php if ($currentIndex < count($donors) - 1): ?>
                window.location.href = '?donor=<?php echo $currentIndex + 2; ?>';
                <?php else: ?>
                alert('🎉 All donors completed!');
                <?php endif; ?>
            }, 1000);
        }

        // Check if donor was already completed
        document.addEventListener('DOMContentLoaded', function() {
            const completed = JSON.parse(localStorage.getItem('importedDonors') || '[]');
            if (completed.includes(<?php echo $currentDonor['no']; ?>)) {
                document.querySelectorAll('.checklist li').forEach((li, index) => {
                    const hasPayment = <?php echo $currentDonor['has_payment'] ? 'true' : 'false'; ?>;
                    if (hasPayment || index < 2) {
                        li.classList.add('done');
                        li.querySelector('i').className = 'fas fa-check-square text-success';
                    }
                });
            }
            
            // Highlight completed donors in sidebar
            completed.forEach(num => {
                const items = document.querySelectorAll('.donor-list-item');
                items.forEach(item => {
                    if (item.innerText.includes('#' + num + ' ')) {
                        item.classList.add('completed');
                    }
                });
            });
        });
    </script>
</body>
</html>

