<?php

namespace App\Models;

use Illuminate\Support\Str;
use DB, Cache;

class Utilities {

    public static function getS3URL($item){
        if(!$item){
            return "";
        }
        return env('S3_URL').$item;
    }

    public static function convertDateShow($date){
        if(!$date){
            return "";
        } else {
            return date("d-M-Y",strtotime($date));
        }
    }

    public static function convertDate($date){
        if(!$date){
            return "";
        } else {
            return date("d-m-Y",strtotime($date));
        }
    }

    public static function convertDateToDB($date){

        if(!isset($date)){
            return null;
        }

        if(!$date){
            return null;
        } else {
            return date("Y-m-d",strtotime($date));
        }
    }

    public static function getColor($doe, $inactive){
        if($inactive == 1){
            return "#ff5b5b";
        } else {
            if( date("Y-m-d",strtotime($doe)) < date("Y-m-d")){
                return "#f6e957";
            } else {
                return "#57f65c";
            }
        }
    }

    public static function getPicture($pic, $type){
        if($pic){
            return Utilities::getS3URL($pic);
        } else {
            return url("assets/images/student.png");
        }
    }

    
    public static function daysDiff($start_date, $end_date){

        return (strtotime($end_date) - strtotime($start_date))/86400 + 1;

    }

    public static function getAge($date){

        return round((strtotime("now") - strtotime($date))/(86400*365));

    }

    public static function getUniqueInTable($table_name, $column_name, $prefix = ''){
        $flag = true;
        while($flag){
            $uniqid = Str::random(10);

            if($prefix) $uniqid = $prefix.$uniqid;

            $check = DB::table($table_name)->where($column_name,$uniqid)->first();
            if(!$check) $flag = false;
        }
        return $uniqid;
    }

    public static function cleanName($name,$operator="_"){
        return preg_replace('/[^a-zA-Z0-9\']/', $operator, $name);
    }

    public static function getExcelDate($value){
        if(is_numeric($value)){
            $UNIX_DATE = ($value - 25569) * 86400;
            $date = date("Y-m-d",$UNIX_DATE);
        } else {
            $date = date("Y-m-d",strtotime($value));
        }

        if($date == "1970-01-01" || $date == "0000-00-00") $date = null;

        return $date;
    }

    public static function getNameFromNumber($num) {
        $numeric = ($num ) % 26;
        $letter = chr(65 + $numeric);
        $num2 = intval(($num ) / 26) - 1;
        if ($num2 >= 0) {
            return Utilities::getNameFromNumber($num2) . $letter;
        } else {
            return $letter;
        }
    }

    public static function getWeekDates($week, $year) {
        $year_start = $year."-01-01";
        $day = date("w",strtotime($year_start));
        if($day == 0){
          $year_start = $year."-01-02";
        }

        $date = strtotime($year_start) + ($week-1)*7*86400;
        $week_day = date("w",$date);

        $week_day_start = $date - ($week_day - 1)*86400;
        $week_day_end = $date + (7-$week_day)*86400;

        $week_start = date('Y-m-d', $week_day_start);
        $week_end = date('Y-m-d', $week_day_end);

        return [$week_start, $week_end];
    }

    public function convertMoneyToWords($number){

        $decimal = round($number - ($no = floor($number)), 2) * 100;
        $hundred = null;
        $digits_length = strlen($no);
        $i = 0;
        $str = array();
        $words = array(0 => '', 1 => 'one', 2 => 'two',
            3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six',
            7 => 'seven', 8 => 'eight', 9 => 'nine',
            10 => 'ten', 11 => 'eleven', 12 => 'twelve',
            13 => 'thirteen', 14 => 'fourteen', 15 => 'fifteen',
            16 => 'sixteen', 17 => 'seventeen', 18 => 'eighteen',
            19 => 'nineteen', 20 => 'twenty', 30 => 'thirty',
            40 => 'forty', 50 => 'fifty', 60 => 'sixty',
            70 => 'seventy', 80 => 'eighty', 90 => 'ninety');
        $digits = array('', 'hundred','thousand','lakh', 'crore');
        while( $i < $digits_length ) {
            $divider = ($i == 2) ? 10 : 100;
            $number = floor($no % $divider);
            $no = floor($no / $divider);
            $i += $divider == 10 ? 1 : 2;
            if ($number) {
                $plural = (($counter = count($str)) && $number > 9) ? 's' : null;
                $hundred = ($counter == 1 && $str[0]) ? ' and ' : null;
                $str [] = ($number < 21) ? $words[$number].' '. $digits[$counter]. $plural.' '.$hundred:$words[floor($number / 10) * 10].' '.$words[$number % 10]. ' '.$digits[$counter].$plural.' '.$hundred;
            } else $str[] = null;
        }
        $Rupees = implode('', array_reverse($str));
        $paise = ($decimal > 0) ? "." . ($words[$decimal / 10] . " " . $words[$decimal % 10]) . ' Paise' : '';
        return ucwords($Rupees ? $Rupees . 'Rupees ' : '') . $paise;

    }

    public static function msword_conversion($str){ 

        $invalid = array('Š'=>'S', 'š'=>'s', 'Đ'=>'Dj', 'đ'=>'dj', 'Ž'=>'Z', 'ž'=>'z',
        'Č'=>'C', 'č'=>'c', 'Ć'=>'C', 'ć'=>'c', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A',
        'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E',
        'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O',
        'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y',
        'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a',
        'æ'=>'a', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e',  'ë'=>'e', 'ì'=>'i', 'í'=>'i',
        'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
        'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y',  'ý'=>'y', 'þ'=>'b',
        'ÿ'=>'y', 'Ŕ'=>'R', 'ŕ'=>'r', "`" => "'", "´" => "'", "„" => ",", "`" => "'",
        "´" => "'", "“" => "\"", "”" => "\"", "´" => "'", "&acirc;€™" => "'",
        "~" => "", "–" => "-", "’" => "'");

        $str = str_replace(array_keys($invalid), array_values($invalid), $str);

        return $str;
    }

    /**
     * Derive quarter number, financial year, start date and end date
     * from a quarter-end date string (Y-m-d).
     *
     * Indian financial year quarters (Apr–Mar):
     *   Q1: Apr 1 – Jun 30   (end 30-06)
     *   Q2: Jul 1 – Sep 30   (end 30-09)
     *   Q3: Oct 1 – Dec 31   (end 31-12)
     *   Q4: Jan 1 – Mar 31   (end 31-03, FY = previous calendar year)
     *
     * Returns ['qtr_no', 'year', 'q_start_date', 'q_end_date'] or null if invalid.
     */
    public static function getQuarterFromEndDate(?string $endDate): ?array
    {
        if (!$endDate) return null;

        $ts = strtotime($endDate);
        if (!$ts) return null;

        $month = (int) date('m', $ts);
        $calYear = (int) date('Y', $ts);

        $map = [
            6  => ['qtr_no' => 1, 'year' => $calYear,     'q_start' => ($calYear    . '-04-01'), 'q_end' => ($calYear    . '-06-30')],
            9  => ['qtr_no' => 2, 'year' => $calYear,     'q_start' => ($calYear    . '-07-01'), 'q_end' => ($calYear    . '-09-30')],
            12 => ['qtr_no' => 3, 'year' => $calYear,     'q_start' => ($calYear    . '-10-01'), 'q_end' => ($calYear    . '-12-31')],
            3  => ['qtr_no' => 4, 'year' => $calYear - 1, 'q_start' => ($calYear    . '-01-01'), 'q_end' => ($calYear    . '-03-31')],
        ];

        if (!isset($map[$month])) return null;

        $q = $map[$month];
        return [
            'qtr_no'      => $q['qtr_no'],
            'year'        => $q['year'],
            'q_start_date' => $q['q_start'],
            'q_end_date'   => $q['q_end'],
        ];
    }

    /**
     * Get the quarter-end date for a given quarter number and financial year.
     * Inverse of getQuarterFromEndDate.
     */
    public static function getQuarterEndDate(int $qtrNo, int $year): ?string
    {
        $map = [
            1 => $year       . '-06-30',
            2 => $year       . '-09-30',
            3 => $year       . '-12-31',
            4 => ($year + 1) . '-03-31',
        ];
        return $map[$qtrNo] ?? null;
    }

    public static function days(){
        $days = [];
        for($i = 1; $i <= 31; $i++){
            if($i < 10){
                $days[] = ["value"=>"0".$i , "label"=>"0".$i];
            } else {
                $days[] = ["value"=>$i."" , "label"=>$i.""];
            }
        }
        return $days;
    }

    public static function months(){
        return [
            ["value"=>"01" , "label"=>"January"],
            ["value"=>"02" , "label"=>"February"],
            ["value"=>"03" , "label"=>"March"],
            ["value"=>"04" , "label"=>"April"],
            ["value"=>"05" , "label"=>"May"],
            ["value"=>"06" , "label"=>"June"],
            ["value"=>"07" , "label"=>"July"],
            ["value"=>"08" , "label"=>"August"],
            ["value"=>"09" , "label"=>"September"],
            ["value"=>"10" , "label"=>"October"],
            ["value"=>"11" , "label"=>"November"],
            ["value"=>"12" , "label"=>"December"],
        ];
    }

    public static function years(){
        $years = [];
        for($i = date("Y") + 1; $i >= 2018; $i--){
            $years[] = ["value"=>$i , "label"=>$i];
        }
        return $years;
    }


}