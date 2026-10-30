<?php
namespace App\Support;
use App\Models\Setting;
class TabularExport
{
    public static function response(array $rows, string $title, string $format)
    {
        $headers = array_keys($rows[0] ?? ['message' => '']);
        foreach ($rows as &$row) foreach ($row as $key => &$value) {
            if (str_ends_with($key, '_minor')) $value = Money::toMajor((int) $value);
            elseif (is_string($value) && preg_match('/^[=+@\-\t\r]/', $value)) $value = "'".$value;
        }
        unset($row, $value);
        if ($format === 'pdf') return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.financial-export', ['school' => Setting::school(), 'headers' => $headers, 'rows' => $rows, 'title' => $title])->setPaper('a4', 'landscape')->stream('report.pdf');
        if ($format === 'xlsx') {
            $path = tempnam(sys_get_temp_dir(), 'tss-report-'); $writer = new \OpenSpout\Writer\XLSX\Writer(); $writer->openToFile($path); $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($headers)); foreach ($rows as $row) $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(array_values($row))); $writer->close(); return response()->download($path, 'report.xlsx')->deleteFileAfterSend(true);
        }
        return response()->streamDownload(function () use ($rows, $headers) { $file = fopen('php://output', 'w'); fputcsv($file, $headers, ',', '"', ''); foreach ($rows as $row) fputcsv($file, array_values($row), ',', '"', ''); fclose($file); }, 'report.csv', ['Content-Type' => 'text/csv']);
    }
}
