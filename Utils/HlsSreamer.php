<?php
/**
 * Created by PhpStorm.
 * User: gsv
 * Date: 26.09.17
 * Time: 12:17
 */

namespace Utils;


use Exception\ArchiveNotFoundException;
use Exception\ErrorException;
use Model\archive\Segment;

class HlsSreamer
{

    public $segment_size = 10;
    public $segment_count = 5;

    private $record_dir;
    private $channel_id;

    private $storage;

    public function __construct($channel_id)
    {
        include_once('/var/www/stalker_portal/storage/config.php');
        $this->record_dir = RECORDS_DIR."archive/";
        $this->channel_id = $channel_id;

        $storages = ORM::for_table('tv_archive')
            ->table_alias('archive')
            ->join('storages', ['archive.storage_name', '=', 'storages.storage_name'])
            ->where('archive.ch_id',$channel_id)
            ->find_many();

        if(count($storages) == 0 ){
            throw new ErrorException("Archive storage not found",500);
        }

        $this->storage = $storages[rand(0,count($storages) - 1 )];

        if(preg_match("@^http://@i",$this->storage->storage_ip))
            $this->storage->storage_ip = preg_replace("@(http://)+@i",'http://',$this->storage->storage_ip);
        else
            $this->storage->storage_ip = 'http://'.$this->storage->storage_ip;
    }

    public function getSegmentsByTime($time, $count = 5) {
        $segments = array();

        $first_segment = $this->getSegmentByTime($time);

        array_push($segments,$first_segment);

        $current_segment = $first_segment;
        $counter = 0;
        do {
            $seg = $this->getSegmentByTime($current_segment->getEndTime() + 1);
            $current_segment = $seg;
            array_push($segments,$seg);
        } while ($counter++<$count);

        return $segments;
    }

    public function getSegmentByTime($time) {

        try {

            $file_handle = fopen($this->getIndexFilePathByTime($time), "r");
            $path = $this->storage->storage_ip. ':' .$this->storage->apache_port. '/archive/'.$this->channel_id.'/';

            while (!feof($file_handle)) {
                $line = explode(",", fgets($file_handle));

                $segment = new Segment($line[0], $line[1], $line[2], $line[3], $line[4],$path);
                if ($segment->getStartTime() <= $time && $segment->getEndTime() >= $time) {
                    fclose($file_handle);
                    return $segment;
                }
            }
            fclose($file_handle);

        }catch (\Exception $e){
            throw new ArchiveNotFoundException("Archive for timestamp $time not found");
        }
        throw new ArchiveNotFoundException("Archive for timestamp $time not found");
    }


    public function getFileTime($time) {
        if ($this->file_in_current_hour(self::getDateByTime($time))) {
            return intval(date("i"))*60 + intval(date("s"));
        }

        // 1 hour
        return 3600;
    }

    public function getFilePathByTime($time){
       return $this->storage->storage_ip. ':' .$this->storage->apache_port. '/archive/' . $this->channel_id . '/'.$this->getFileNameByTime($time);
    }

    public function getIndexFilePathByTime($time){
        return $this->storage->storage_ip. ':' .$this->storage->apache_port. '/archive/' . $this->channel_id . '/'.$this->getIndexFileNameByTime($time);
    }

    public function getFileNameByTime($time){
        return self::getDateByTime($time).'.mpg';
    }

    public function getIndexFileNameByTime($time){
        return self::getDateByTime($time).'.idx';
    }

    public static function getDateByTime($time) {
      return date("Ymd-H",$time);
    }

    private function file_in_current_hour($date){
        return $date == date("Ymd-H");
    }

    public function getDuration($time) {
        return $time - strtotime (date("d-m-Y H:00:00",$time));
    }
}