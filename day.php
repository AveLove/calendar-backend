<?php
require_once('init.php');
require_once('functions.php');
require_once('bible.php');

class Day
{
    protected $isDebug = false;
    protected $perehod;
    protected $neperehod;
    protected $bReadings;
    protected $sundayMatinsGospels;
    protected $zachala;
    protected $saints;
    protected $prazdnikTitle;
    protected $skipRjadovoe;
    protected $noLiturgy;
    protected $dayOfWeekNumber;

    protected $dayOfWeekNames = ['воскресение', 'понедельник', 'вторник', 'среду', 'четверг', 'пятницу', 'субботу'];

    protected $liturgyPartKeys = ['Прокимен', 'Аллилуарий', 'Причастен', 'Входной стих', 'Вместо Трисвятого', 'Задостойник', 'Отпуст'];

    protected function getStaticData($datestamp)
    {
        $d = date('Y-m-d', $datestamp);
        $filename = 'Data/processed/' . $d;
        if (file_exists($filename)) {
            return json_decode(file_get_contents($filename), true);
        } else {
            return null;
        }
    }

    protected function normalizeDayOfWeek($dayOfWeekNumber)
    {
        if ($dayOfWeekNumber < 0) {
            return $dayOfWeekNumber + 7;
        }
        if ($dayOfWeekNumber > 6) {
            return $dayOfWeekNumber - 7;
        }
        return $dayOfWeekNumber;
    }

    protected function getDayAfter($date, $dayNumber = 1, $shTimes = 0, $noJumpIfSameDay = 0)
    {
        $day_stamp = strtotime($date);
        $currentDayNumber = date('N', $day_stamp);
        if ($currentDayNumber == 7)
            $currentDayNumber = 0;
        if ($currentDayNumber < $dayNumber) {
            $shiftToDay = $dayNumber - $currentDayNumber;
        } else if ($currentDayNumber == $dayNumber) {
            if ($noJumpIfSameDay == 0) {
                $shiftToDay = 7;
            }
        } else if ($currentDayNumber > $dayNumber) {
            $shiftToDay = 7 - $currentDayNumber + $dayNumber;
        }
        $day_after = strtotime('+' . $shiftToDay . ' day', $day_stamp);
        return $day_after;
    }

    protected function getDayBefore($date, $dayNumber = 1, $shTimes = 0)
    {
        $day_stamp = strtotime($date);
        $currentDayNumber = (int) date('N', $day_stamp);
        if ($currentDayNumber == 7) {
            $currentDayNumber = 0;
        }

        if ($dayNumber === 'w') {
            if ($currentDayNumber == 0) {
                $shiftToDay = 2;
            } else if ($currentDayNumber == 6) {
                $shiftToDay = 1;
            } else {
                $shiftToDay = 0;
            }
        } else {
            if ($currentDayNumber > $dayNumber) {
                $shiftToDay = $currentDayNumber - $dayNumber;
            } else if ($currentDayNumber == $dayNumber) {
                $shiftToDay = 7;
            } else if ($currentDayNumber < $dayNumber) {
                $shiftToDay = $currentDayNumber + 7 - $dayNumber;
            }
        }
        //additional shift of weeks
        $shiftToDay = $shiftToDay + $shTimes * 7;

        $day_after = strtotime('-' . $shiftToDay . ' day', $day_stamp);
        return $day_after;
    }

    protected function getDayNearest($date, $dayNumber = 1)
    {
        $day_stamp = strtotime($date);
        $dayBefore = $this->getDayBefore($date, $dayNumber);
        $dayAfter = $this->getDayAfter($date, $dayNumber);
        if (2 * $day_stamp > $dayBefore + $dayAfter) {
            return $dayAfter;
        } else if (2 * $day_stamp < $dayBefore + $dayAfter) {
            return $dayBefore;
        } else if (2 * $day_stamp == $dayBefore + $dayAfter) {
            return $day_stamp;
        }
    }

    protected function getKey($key, $d_stamp)
    {
        $d_Y = date('Y', $d_stamp); //YEAR, OC

        if ($key == '25/12+0' || $key == '25/12+6') {
            if (date('m', $d_stamp) == '01') //if december
                $d_Y--;
        }
        if ($key == '06/01-6' || $key == '06/01-0') {
            if (date('m', $d_stamp) == '12') //if december
                $d_Y++;
        }
        if (preg_match("/(\d\d\/\d\d)(.)?(\w)?#?(\d)?/u", $key, $out)) {
            $shDateO = str_replace("/", "-", $out['1']) . "-" . $d_Y; //date, OC, with slashes
            $sh_sign = $out['2'] ?? null; //operation sign
            $shDayn = $out['3'] ?? null; //day number,0 - sunday
            $shTimes = $out['4'] ?? null;
            $shDateStamp = strtotime('+13 days', strtotime($shDateO)); //timestamp NC
            $shDate = date('d-m-Y', $shDateStamp); //NC date
            switch ($sh_sign) {
                case '+':
                    $res_key = date('d/m', strtotime('-13 days', $this->getDayAfter($shDate, $shDayn, $shTimes))); //OC, key
                    break;
                case '-':
                    $res_key = date('d/m', strtotime('-13 days', $this->getDayBefore($shDate, $shDayn, $shTimes))); //OC, key
                    break;
                case '~':
                    $res_key = date('d/m', strtotime('-13 days', $this->getDayNearest($shDate, $shDayn))); //OC, key
                    break;
                case '':
                    $res_key = date('d/m', strtotime($shDateO)); //OC, key
                    break;
            }
            return $res_key;
        }
    }

    protected function getNeperehod($dateStamp)
    {
        $reading_array = [];
        $d_stamp = strtotime('-13days', $dateStamp); //date stamp, OC

        foreach ($this->neperehod as $key => $value) {
            $res_key = $this->getKey($key, $d_stamp);
            if ($res_key == date('d/m', $d_stamp)) {
                foreach ($value as $v) {
                    $reading_array[] = $v;
                }
            }
        }
        return $reading_array;
    }

    protected function getBReadings($dateStamp)
    {
        foreach ($this->bReadings as $key => $value) {
            if ($key === date('j/n/Y', $dateStamp)) {
                foreach ($value as $v) {
                    return $v;
                }
            }
        }
        return [];
    }

    protected function processSaints($saints)
    {
        $saints = str_replace("#SR", "", $saints);
        $saints = str_replace("#NSR", "", $saints);
        $saints = preg_replace('/(?:\r\n|\r|\n)/', '<br>', $saints);
        $saints = preg_replace('/#TP(.)/', '<img src="/assets/icons/$1.svg"/>', $saints);
        $saints = str_replace('o.svg"', 'o.svg" alt="Без знака"', $saints);
        $saints = str_replace('0.svg"', '0.svg" alt="Без знака"', $saints);
        $saints = str_replace('1.svg"', '1.svg" alt="Cовершается служба, не отмеченная в Типиконе никаким знаком"', $saints);
        $saints = str_replace('2.svg"', '2.svg" alt="Совершается служба на шесть"', $saints);
        $saints = str_replace('3.svg"', '3.svg" alt="Совершается служба со славословием"', $saints);
        $saints = str_replace('4.svg"', '4.svg" alt="Совершается служба с полиелеем"', $saints);
        $saints = str_replace('5.svg"', '5.svg" alt="Совершается всенощное бдение"', $saints);
        $saints = str_replace('6.svg"', '6.svg" alt="Совершается служба великому празднику"', $saints);

        $saints = preg_replace_callback('#href="https://www.holytrinityorthodox.com/ru/calendar/los/(.*?).htm"#i', function ($matches) {
            $key = $matches[1];
            $key = str_replace("/", "-", $key);
            $key = strtolower($key);
            return 'data-saint="' . $key . '"';
        }, $saints);
        return $saints;
    }
    protected function processPerehods($week, $dayOfWeekNumber, $gospelShift, $weekOld, $dateStampO, $year, $easterStamp)
    {
        $dayweek = $week . ';' . $dayOfWeekNumber; //concat key
        //OVERLAY GOSPEL SHIFT
        $dayweek_gospelshift = ($week + $gospelShift) . ';' . $dayOfWeekNumber; //concat key
        $perehods = $this->perehod[$dayweek];
        $ap = explode(';', $perehods[0]['readings']['Литургия']);
        $ap = explode(';', $perehods[0]['readings']['Литургия']);
        $manyReads = $ap[2] ?? null;
        $ap = $ap[0];
        $gs = explode(';', $this->perehod[$dayweek_gospelshift][0]['readings']['Литургия']);
        $gs = $gs[1] ?? null;
        if (($ap || $gs) && !$manyReads) {
            $perehods[0]['readings']['Литургия'] = $ap . ';' . $gs;
        }

        return $perehods;
    }

    protected function processWeekTitle($week_title, $week,  $weekOld)
    {
        if ($this->dayOfWeekNumber == 0) {
            $sedmned = "Неделя";
            $weekOld--;
        } else {
            $sedmned = "Седмица";
        }
        if (!$week_title) {
            if ($weekOld == 1) {
                $week_title = "Светлая седмица";
            } else if ($weekOld < 8) {
                if ($this->dayOfWeekNumber == 0) {
                    $weekOld++;
                }
                $week_title = "$sedmned $weekOld-я по Пасхе";
            } else if ($week > 43) {
                $week_title = "$sedmned " . ($week - 43) . "-я Великого поста";
            } else if ($weekOld < 46) {
                $week_title = "$sedmned " . ($weekOld - 7) . "-я по Пятидесятнице";
            }
        }
        return $week_title;
    }
    protected function processReadings($dayDataEntries)
    {
        $weekend = false;
        if ($this->dayOfWeekNumber == 0 || $this->dayOfWeekNumber == 6) {
            $weekend = true;
        }
        foreach ($dayDataEntries as $dayDataEntry) {
            if (!$dayDataEntry['reading_title']) {
                $dayDataEntry['reading_title'] = 'Рядовое';
            }
            $reading_title = $dayDataEntry['reading_title'];
            //if($dayDataEntry['prazdnikTitle'])
            //	$this->prazdnikTitle .= $dayDataEntry['prazdnikTitle'].'<br/>';
            if (isset($dayDataEntry['readings'])) {
                foreach ($dayDataEntry['readings'] as $serviceKey => $readings) {
                    //if(!$nr[$serviceKey][$reading_title])
                    if ($readings) {
                        if (!isset($nr[$serviceKey][$reading_title])) {
                            $nr[$serviceKey][$reading_title] = [];
                        }
                        $nr[$serviceKey][$reading_title][] = $readings;
                    }
                }
            }

            //order services
            $nr_or['Утреня'] = $nr['Утреня'] ?? null;
            $nr_or['1-й час'] = $nr['1-й час'] ?? null;
            $nr_or['3-й час'] = $nr['3-й час'] ?? null;
            $nr_or['6-й час'] = $nr['6-й час'] ?? null;
            $nr_or['9-й час'] = $nr['9-й час'] ?? null;
            $nr_or['Литургия'] = $nr['Литургия'] ?? null;
            $nr_or['Вечерня'] = $nr['Вечерня'] ?? null;
            $nr_or['На освящении воды'] = $nr['На освящении воды'] ?? null;
        }
        // if ((count($nr_or['Утреня'] ?? []) > 1) && $nr_or['Утреня']['Воскресное евангелие']) {
        //unset sunday saint's matins?
        // }
        $resultArray = [];
        foreach ($nr_or as $serviceKey => $nr2) {
            if ($this->noLiturgy && $serviceKey == 'Литургия') {
                continue;
            }
            if ($nr2) {
                foreach ($nr2 as $rtitle => $_readings) {
                    foreach ($_readings as $readings) {
                        $readingFound = false;
                        $readings = str_replace('–', '-', $readings);
                        if ($rtitle === 'Рядовое' && $this->skipRjadovoe && $serviceKey === 'Литургия') {
                            continue;
                        }
                        $fragments = [];
                        if (strpos($readings, ' ') === false) { // this is zachalo arrays: 25;Jh25
                            foreach (explode(';', $readings) as $reading) {
                                $reading_ex = explode('/', $reading);
                                if ($weekend && isset($reading_ex[1])) {
                                    $reading = $reading_ex[1];
                                } else {
                                    $reading = $reading_ex[0];
                                }
                                if (isset($this->zachala[$reading])) {
                                    $readingFound = true;
                                    $fragments[] = trim($this->zachala[$reading]);
                                }
                            }
                        } else { //this is verse: Мих. IV, 2-3; 5; VI, 2-5; 8; V, 4
                            $fragments[] = trim($readings);
                            $readingFound = true;
                        }
                        // If reading wasn't found just output it as is
                        if (!$readingFound) {
                            $fragments[] = trim($readings);
                        }
                        if (!isset($resultArray[$serviceKey])) {
                            $resultArray[$serviceKey] = [];
                        }
                        $resultArray[$serviceKey][$rtitle] = array_merge($resultArray[$serviceKey][$rtitle] ?? [], $fragments);
                    }
                }
            }
        }

        return $resultArray;
    }

    protected function check_skipRjadovoe($saints)
    {
        //skip rjadovoje reading if not sunday and Great prazdnik
        $skipRjadovoe = false;
        if ((strpos($saints, "#TP6") || strpos($saints, "#TP5")) && ($this->dayOfWeekNumber != 0))
            $skipRjadovoe = true;
        //skip rjadovoe
        if (strpos($saints, "#SR") !== false)
            $skipRjadovoe = true;
        if (strpos($saints, "#NSR") !== false)
            $skipRjadovoe = false;
        return $skipRjadovoe;
    }

    protected function getDayData($perehod)
    {
        $googleUrl = 'https://docs.google.com/spreadsheet/pub?hl=en&hl=en&key=0AnIrRiVoiOUSdENKckd0Vm1RbVhUMGVOQWNIZUNBUmc&single=true&output=csv&gid=';
        $filename = 'Data/cache_' . ($perehod ? 'perehod' : 'neperehod') . '.csv';
        $gid = $perehod ? 4 : 0;
        if ($this->isDebug) {
            unlink($filename);
        }
        if (!file_exists($filename)) {
            file_put_contents($filename, file_get_contents($googleUrl . $gid));
        }
        $file = fopen($filename, 'r');
        $indexes = null;
        while (($line = fgetcsv($file)) !== FALSE) {
            if (!$indexes) {
                $indexes = $line;
            } else {
                $mappedLine = [];
                foreach ($line as $index => $cell) {
                    $key = $indexes[$index];
                    $mappedLine[$key] = $cell;
                }
                $weekday = $mappedLine['Дата'];
                $data = [];
                $data['week_title'] = $mappedLine['Неделя'];
                $data['saints'] = $mappedLine['Святые'];
                $data['reading_title'] = $mappedLine['Заглавие чтения'];
                $data['readings']['Утреня'] = $mappedLine['Утреня'];
                $data['readings']['Литургия'] = $mappedLine['Литургия'];
                $data['readings']['Вечерня'] = $mappedLine['Вечерня'];
                $data['readings']['1-й час'] = $mappedLine['1-й час'];
                $data['readings']['3-й час'] = $mappedLine['3-й час'];
                $data['readings']['6-й час'] = $mappedLine['6-й час'];
                $data['readings']['9-й час'] = $mappedLine['9-й час'];
                $data['readings']['На освящении воды'] = $mappedLine['На освящении воды'];
                $data['prayers'] = $mappedLine['Тропари Литургия'];
                $data['prayersOther'] = $mappedLine['Тропари Остальные'];
                foreach ($this->liturgyPartKeys as $liturgyPartKey) {
                    $fieldValue = $mappedLine[$liturgyPartKey];
                    if (in_array($liturgyPartKey, ['Прокимен', 'Аллилуарий', 'Причастен'])) {
                        $fieldValue = json_decode($fieldValue, true);
                    }
                    $data['liturgyParts'][$liturgyPartKey] = $fieldValue;
                }
                if ($perehod) {
                    $this->perehod[$weekday][] = $data;
                } else {
                    $this->neperehod[$weekday][] = $data;
                }
            }
        }
        fclose($file);
    }

    protected function init($date)
    {
        if (!file_exists('Data')) {
            mkdir('Data', 0777, true);
        }
        $googleUrl = 'https://docs.google.com/spreadsheet/pub?hl=en&hl=en&key=0AnIrRiVoiOUSdENKckd0Vm1RbVhUMGVOQWNIZUNBUmc&single=true&output=csv&gid=';

        $this->getDayData(true);
        $this->getDayData(false);

        $filename = 'Data/cache_zachala_apostol.csv';
        $gid = 3;
        if ($this->isDebug) {
            unlink($filename);
        }
        if (!file_exists($filename)) {
            file_put_contents($filename, file_get_contents($googleUrl . $gid));
        }
        $file = fopen($filename, 'r');
        while (($line = fgetcsv($file)) !== FALSE) {
            $key = $line[0];
            $reading = $line[1];
            $this->zachala[$key] = $reading;
        }
        fclose($file);

        $filename = 'Data/cache_zachala_gospel.csv';
        $gid = 18;
        if ($this->isDebug) {
            unlink($filename);
        }
        if (!file_exists($filename)) {
            file_put_contents($filename, file_get_contents($googleUrl . $gid));
        }
        $file = fopen($filename, 'r');
        while (($line = fgetcsv($file)) !== FALSE) {
            $key = $line[0];
            $reading = $line[1];
            $this->zachala[$key] = $reading;
        }
        fclose($file);

        $filename = 'Data/cache_bReadings.csv';
        $gid = 19;
        if ($this->isDebug) {
            unlink($filename);
        }
        if (!file_exists($filename)) {
            file_put_contents($filename, file_get_contents($googleUrl . $gid));
        }
        $file = fopen($filename, 'r');
        while (($line = fgetcsv($file)) !== FALSE) {
            $date = $line[0];
            $bReadingsArray = [];
            if ($line[1]) {
                $bReadingsArray['Утром'] = ['unnamed' => [$line[1]]];
            }
            if ($line[2]) {
                $bReadingsArray['Вечером'] = ['unnamed' => [$line[2]]];
            }
            $this->bReadings[$date][] = $bReadingsArray;
        }
        fclose($file);

        $file = fopen('Data/static_sunday_matins_gospels.csv', 'r');
        while (($line = fgetcsv($file)) !== FALSE) {
            $this->sundayMatinsGospels[$line[0]] = $line[1];
        }
        fclose($file);

        $filename = 'Data/cache_saints.csv';
        $gid = 5;
        if (!file_exists($filename)) {
            file_put_contents($filename, file_get_contents($googleUrl . $gid));
        }
        $file = fopen($filename, 'r');
        while (($line = fgetcsv($file)) !== FALSE) {
            $this->saints[$line[0]] = $line[1];
        }
        fclose($file);
    }

    // Flatten $dayDataEntries
    protected function reduceDayData($dayDataEntries)
    {
        $result = smartMerge($dayDataEntries, ['saints', 'prayers', 'prayersOther'], ['week_title']);
        $liturgyPartsEntries = array_map(function ($d) {
            return $d['liturgyParts'] ?? [];
        }, $dayDataEntries);
        $result['liturgyParts'] = smartMerge($liturgyPartsEntries);
        return $result;
    }
    /**
     * @param string $date
     * @return string
     */
    public function run($date = null)
    {
        $debug = '';
        if (!$date)
            $date = date('Ymd');
        $this->init($date);

        $date = date('Ymd', strtotime("-13 days", strtotime($date)));
        $dateStampO = strtotime($date);
        $dateStamp = strtotime("+13 days", $dateStampO);
        $this->dayOfWeekNumber = date('N', $dateStamp) % 7;

        $year = date("Y", $dateStamp);
        $easterStamp = easter($year);

        if ($easterStamp > $dateStamp) {
            $year = $year - 1;
            $easterStamp = easter($year);
        }
        $nextEasterStamp = easter($year + 1);
        $debug .= "год" . $year;

        $week = datediff('ww', $easterStamp, $dateStamp, true) + 1;

        $fast = null;
        if (strtotime('15-11-' . $year) <= $dateStampO && $dateStampO <= strtotime('24-12-' . ($year))) {
            $fast = "Рождественский (Филиппов) пост";
        }
        if (strtotime('01-08-' . $year) <= $dateStampO && $dateStampO <= strtotime('14-08-' . ($year))) {
            $fast = "Успенский пост";
        }
        if ((11 <= $week) && $dateStampO <= strtotime('29-06-' . ($year))) {
            $fast = "Петров пост";
        }


        $sunday_after_krest = $this->getDayAfter('27-09-' . $year, 0);
        $mondayAfterSundayAfterKrest = strtotime("+1 day", $sunday_after_krest);
        $week_after_krest = datediff('ww', $easterStamp, $mondayAfterSundayAfterKrest, true) + 1;
        $krestDiff = 25 - $week_after_krest;
        $monday18thWeek = strtotime("+24 weeks 1 day", $easterStamp);

        if ($dateStamp >= $mondayAfterSundayAfterKrest) { //in any case, start to read Luke only on Monday after Sunday after Krestovozdvijenije
            $gospelShift = $krestDiff;
        } else if ($dateStamp >= $monday18thWeek) {
            $gospelShift = -6 + $krestDiff; //shift back to Mt and skip Mk weeks
        } else {
            $gospelShift = 0;
        }

        $prosv = strtotime('19-01-' . ($year + 1));
        $mondayAfterProsv = $this->getDayAfter('19-01-' . ($year + 1), 1, 0, 1); //NB: Weird stuff, but if Prosv is on Monday, the reset should occur the same day
        $weekAfterProsv = $this->getDayAfter('19-01-' . ($year + 1), 0);
        $weekToEaster = datediff('ww', $nextEasterStamp, $dateStamp, true) + 1;

        $weekOld = $week; //save old week number, neccessary for matins order
        if ($week > 40 || $dateStamp >= $mondayAfterProsv) {
            if (
                ($weekToEaster == -12 && $this->dayOfWeekNumber != 0)
                ||
                ($weekToEaster == -11 && $this->dayOfWeekNumber == 0)
            ) {
                $debug .= "weekToEaster-14!";
                $weekToEaster = $weekToEaster - 14;
            } else if ($weekToEaster <= -12) {
                $debug .= "weekToEaster+0!";
                $weekToEaster = $weekToEaster;
            }
            $debug .= "week season!";
            $week = 50 + $weekToEaster;
        }
        if ($dateStamp >= $mondayAfterProsv) {
            $debug .= "gospel shift reset!";
            $gospelShift = 0;
        }
        $debug .= "Неделя" . $week;
        $debug .= "Неделя_old" . $weekOld;
        $debug .= "Понедельник по крестовоздвижению: " . date("Ymd", $mondayAfterSundayAfterKrest) . "| 17-я неделя " . date('Ymd', $monday18thWeek) . "<br/>";
        $debug .= "Неделя по просвящении " . date('Ymd', $weekAfterProsv);
        $debug .= "<br/>Сдвиг: крест" . $krestDiff . "|еванг" . $gospelShift;
        $debug .= "<br/>Неделя по пасхе: " . $week;
        $debug .= "<br/>Недель до следующей пасхи: " . $weekToEaster;


        //matins sunday
        $matinsZachalo = null;
        $matins_key = null;
        if ($this->dayOfWeekNumber == 0) {
            if ($weekOld >= 9) {
                $matins_key = ($weekOld - 9) % 11 + 1;
            } else if (1 < $weekOld) {
                $matins_pre50 = explode(",", "1,3,4,7,8,10,9");
                $matins_key = $matins_pre50[$weekOld - 2];
            }
            $matinsZachalo = $matins_key ? $this->sundayMatinsGospels[$matins_key] : null;
        }

        //glass
        $glas = (($weekOld - 1) % 8);
        $glas = $glas ? $glas : 8;
        if (($weekOld == 1) || ($week == 50)) {
            $glas = null;
        }

        $perehods = $this->processPerehods($week, $this->dayOfWeekNumber, $gospelShift, $weekOld, $dateStampO, $year, $easterStamp);


        if ($week + $gospelShift == 36 && $this->dayOfWeekNumber == 0) {
            $debug .= "gospel shifted from praotez";
            $praotecStamp = strtotime(str_replace('/', '-', $this->getKey('25/12-0#1', $dateStampO) . "/" . $year));

            $ap = explode(";", $perehods[0]['readings']['Литургия']);
            $manyReads = isset($ap[2]);
            $ap = $ap[0];
            $processedPerehodForPraotez = $this->processPerehods(datediff('ww', $easterStamp, $praotecStamp, true) + 3, "0", $gospelShift, $weekOld, $dateStampO, $year, $easterStamp);
            $gs = explode(";", $processedPerehodForPraotez[0]['readings']['Литургия']);
            $gs = $gs[1];
            if (($ap || $gs) && !$manyReads) {
                $perehods[0]['readings']['Литургия'] = $ap . ";" . $gs;
            }
        }
        if ($week == 37 && $this->dayOfWeekNumber == 0) {
            $debug .= "apostol shifted from praotez";
            $praotecStamp = strtotime(str_replace('/', '-', $this->getKey('25/12-0#1', $dateStampO) . "/" . $year));
            $processedPerehodForPraotez = $this->processPerehods(datediff('ww', $easterStamp, $praotecStamp, true) + 3, "0", $gospelShift, $weekOld, $dateStampO, $year, $easterStamp);
            $ap = explode(";", $processedPerehodForPraotez[0]['readings']['Литургия']);
            $ap = $ap[0];
            $gs = explode(";", $perehods[0]['readings']['Литургия']);
            $manyReads = isset($gs[2]);
            $gs = $gs[1];
            if (($ap || $gs) && !$manyReads)
                $perehods[0]['readings']['Литургия'] = $ap . ";" . $gs;
        }


        //OVERLAY SUNDAY MATINS
        $mat['readings']['Утреня'] = $matinsZachalo;
        $mat['reading_title'] = 'Воскресное евангелие';
        $perehods[] = $mat;

        // Merge perehod and neperehod data entries for given day
        $neperehodArray = $this->getNeperehod($dateStamp);
        if (!$perehods) {
            $dayDataEntries = $neperehodArray;
        } else if (!$neperehodArray) {
            $dayDataEntries = $perehods;
        } else {
            $dayDataEntries = array_merge($perehods, $neperehodArray);
        }

        $dayData = $this->reduceDayData($dayDataEntries);

        $saintsThisDay = trim($this->saints[date('d/m', $dateStampO)]);

        if ($dayData['saints'] && $saintsThisDay) {
            $dayData['saints'] .= "<br/>";
        }
        $dayData['saints'] .= $saintsThisDay;


        $this->skipRjadovoe = $this->check_skipRjadovoe($dayData['saints']);

        //PERENOS CHTENIJ
        if (($this->dayOfWeekNumber != 0) && (!$this->skipRjadovoe)) { //not on Sunday, check for move forward
            $next_dateStampO = strtotime("+1 days", $dateStampO);
            $next_dateStamp = strtotime("+1 days", $dateStamp);

            //combine saints
            $t = $this->getNeperehod($next_dateStamp);
            $next_saints = $t['0']['saints'] ?? '';
            $r = $this->processPerehods($week, $this->normalizeDayOfWeek($this->dayOfWeekNumber + 1), $gospelShift, $weekOld, $dateStampO, $year, $easterStamp);
            $next_saints .= $r['0']['saints'] ?? '';
            $next_saints .= $this->saints[date('d/m', $next_dateStampO)];

            if ($this->check_skipRjadovoe($next_saints)) {
                $debug .= "<br>something holy is around";

                $r['0']['reading_title'] = 'За ' . $this->dayOfWeekNames[$this->normalizeDayOfWeek($this->dayOfWeekNumber + 1)];
                $dayDataEntries = array_merge($dayDataEntries, $r);
            }
        }
        //refactor at will
        //this must match http://c.psmb.ru/pravoslavnyi-kalendar/date/20130803?debug=1, also look at the previous days
        if (($this->dayOfWeekNumber != 0) && (!$this->skipRjadovoe)) { //not on Sunday, check for move forward
            $next_dateStampO = strtotime("-1 days", $dateStampO);
            $next_dateStamp = strtotime("-1 days", $dateStamp);

            //combine saints
            $t = $this->getNeperehod($next_dateStamp);
            $next_saints = $t['0']['saints'] ?? '';
            $r = $this->processPerehods($week, $this->normalizeDayOfWeek($this->dayOfWeekNumber - 1), $gospelShift, $weekOld, $dateStampO, $year, $easterStamp);
            $next_saints .= $r['0']['saints'] ?? '';
            $next_saints .= $this->saints[date('d/m', $next_dateStampO)];

            if ($first_check = $this->check_skipRjadovoe($next_saints)) {
                $next_dateStampO = strtotime("-2 days", $dateStampO);
                $next_dateStamp = strtotime("-2 days", $dateStamp);

                //combine saints
                $t = $this->getNeperehod($next_dateStamp);
                $next_saints = $t['0']['saints'] ?? '';
                $r2 = $this->processPerehods($week, $this->normalizeDayOfWeek($this->dayOfWeekNumber - 2), $gospelShift, $weekOld, $dateStampO, $year, $easterStamp);
                $next_saints .= $r2['0']['saints'] ?? '';
                $next_saints .= $this->saints[date('d/m', $next_dateStampO)];
                if ($this->check_skipRjadovoe($next_saints) || ($this->dayOfWeekNumber == 2)) {
                    $debug .= "<br>something very holy is around!";

                    $r['0']['reading_title'] = 'За ' . $this->dayOfWeekNames[$this->normalizeDayOfWeek($this->dayOfWeekNumber - 1)];
                    $dayDataEntries = array_merge($r, $dayDataEntries);
                }
            }
        }


        if ($this->dayOfWeekNumber == 0 && $glas && $week != 8) {
            require('Data/static_sunday_troparion.php');
            if (!isset($dayData['prayers'])) {
                var_dump(($dayData));
                die();
            }
            if ($dayData['prayers'] && $sunday_troparion[$glas]) {
                $dayData['prayers'] .= "<br/>";
            }
            $dayData['prayers'] .= $sunday_troparion[$glas];
        }


        //skip rjad on sochelnik HACK HACK HACK
        if ($this->dayOfWeekNumber == 5 && ($date == $year . '1222')) {
            $this->noLiturgy = true;
        }
        $debug .= '<br/>пропуск рядового чтения:' . $this->skipRjadovoe;


        // @TODO: bring back static data
        $staticData = ['readings' => null];
        // $staticData = $this->getStaticData($dateStamp);
        // if ($staticData) {
        //     $readings = '';
        //     foreach ($staticData['readings'] as $serviceType => $readingGroup) {
        //         $serviceType = $serviceType == 'Утр' ? 'Утреня' : $serviceType;
        //         $serviceType = $serviceType == 'Лит' ? 'Литургия' : $serviceType;
        //         $readings .= $serviceType . ": <ul>";
        //         foreach ($readingGroup as $readingType => $reading) {
        //             // TODO: check if this needs to be urlencoded
        //             $readingStr = join(' ', preg_replace('/<a\s+href="([^"]+)"\s*>/', '<a class="reading" href="http://bible.psmb.ru/bible/book/$1/">', $reading));
        //             $readingType = $readingType == 'Рядовое' ? '' : $readingType . ": ";
        //             $readings .= "<li>" . $readingType . str_replace('*', '', $readingStr) . "</li>";
        //         }
        //         $readings .= "</ul>";
        //     }
        //     if ($staticData['readings']) {
        //         $dynamicData['readings'] = $readings;
        //     }

        //     $dynamicData['comment'] = preg_replace('/<a\s+href="([^"]+)"\s*>/', '<a class="reading" href="http://bible.psmb.ru/bible/book/$1/">', $staticData['comment'] ?? '');
        // }

        $jsonArray = [
            "title" => $this->processWeekTitle($dayData['week_title'], $week, $weekOld) ?? null,
            "glas" => $glas ?? null,
            "lent" => $fast ?? null,
            "readings" => $staticData['readings'] ?? $this->processReadings($dayDataEntries) ?? null,
            'bReadings' => $this->getBReadings($dateStamp),
            "saints" => $this->processSaints($dayData['saints']) ?? null,
            "prayers" => $dayData['prayers'] ?? null,
            "prayersOther" => $dayData['prayersOther'] ?? null,
            "liturgyParts" => $dayData['liturgyParts'],
        ];

        return $jsonArray;
    }
}
