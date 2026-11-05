<?php

namespace App;

use App\Models\Utilities;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;

error_reporting(0);
ini_set('MAX_EXECUTION_TIME', '-1');

$spreadsheet = new Spreadsheet;
$spreadsheet->setActiveSheetIndex(0);
$activeSheet = $spreadsheet->getActiveSheet();
$activeSheet->setTitle('Directors');

$ar_fields = [
    'sn',
    'din_no',
    'salutation_name',
    'dir_name',
    'gender',
    'dob',
    'black_listed',
    'notes',
    'created_at',
];
$ar_names = [
    'SN',
    'DIN No',
    'Salutation',
    'Director Name',
    'Gender',
    'Date of Birth',
    'Blacklisted',
    'Notes',
    'Created At',
];
$ar_width = [
    '8',
    '16',
    '12',
    '36',
    '10',
    '16',
    '14',
    '40',
    '20',
];

$row = 1;
$i   = 0;

foreach ($ar_names as $index => $ar) {
    $cell_val = Utilities::getNameFromNumber($i);
    $activeSheet->setCellValue($cell_val . $row, $ar);
    $activeSheet->getColumnDimension($cell_val)->setWidth($ar_width[$index]);
    $i++;
}

$max_col = Utilities::getNameFromNumber($i - 1);
$activeSheet->getStyle('A1:' . $max_col . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('b3caf2');
$activeSheet->getStyle('A1:' . $max_col . '1')->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));

$count = 1;
$row   = 2;

foreach ($export_director_data as $item) {
    $i = 0;

    foreach ($ar_fields as $ar) {
        $cell_val = Utilities::getNameFromNumber($i);

        if ($ar == 'sn') {
            $var = $count++;
        } elseif ($ar == 'gender') {
            $var = isset($item->$ar) ? ((int) $item->$ar === 1 ? 'Male' : 'Female') : '';
        } elseif ($ar == 'black_listed') {
            $var = isset($item->$ar) ? ((int) $item->$ar === 1 ? 'Yes' : 'No') : 'No';
        } elseif ($ar == 'dob') {
            $var = !empty($item->$ar) ? date('d-M-Y', strtotime($item->$ar)) : '';
        } elseif ($ar == 'created_at') {
            $var = !empty($item->$ar) ? date('d-M-Y H:i', strtotime($item->$ar)) : '';
        } else {
            $var = isset($item->$ar) ? $item->$ar : '';
        }

        $activeSheet->setCellValue($cell_val . $row, $var);
        $i++;
    }

    $row++;
}

$activeSheet->getStyle('A2:' . $max_col . ($row - 1))->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FF000000'));

$spreadsheet->setActiveSheetIndex(0);

if (!file_exists(public_path('temp'))) {
    mkdir(public_path('temp'), 0755, true);
}

$filename = 'Director_List_' . strtotime('now') . '.xlsx';
$writer   = new Xlsx($spreadsheet);
$writer->save(public_path('temp/' . $filename));

$data['export'] = 'temp/' . $filename;
