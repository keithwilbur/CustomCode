<?php
/*
 * @copyright Copyright (c) Disney - All Rights Reserved
 */

require_once (PATH_TO_INC . "/entities/Task.php");
require_once (PATH_TO_INC . "/tasks/_task.php");
require_once (PATH_TO_INC . "/system/TaskFactory.php");
require_once (PATH_TO_INC . "/entities/MediaObject.php");
require_once (PATH_TO_INC . "/entities/MediaClass.php");
require_once PATH_TO_INC . "/entities/MediaObjectInputs.php";
require_once (PATH_TO_INC . "/entities/Title.php");
require_once (PATH_TO_INC . "/entities/Language.php");
require_once (PATH_TO_INC . "/lib/MOUtils.php");


class GatherAudioMediaObjects extends Task implements _task {

    const TASK_CLASS = "GatherAudioMediaObjects";

    const CSV_FILEPATH = "csv_filepath";
    const TITLE_ID = "title_id";
    const TITLE_VERSION_ID = "title_version_id";


    private $params;
    private $createdMOs = [];
    private $foundMOs = [];
    private $invalidRows = [];
    private $titleABBR;
    private $basePath;


    /**
     * GatherAudioMediaObjects constructor.
     * @param $data
     */
    public function __construct($data) {
        parent::__construct($data);
    }

    /**
     * version
     * Return my version.
     * @return string version
     */
    public function version() {
        return "1.0";
    }

    /**
     * @return string
     */
    public function documentation() {
        return "<h2>Gather DC Audio Media Objects</h2>"
            . "<h4>Description:</h4>"
            . "<div>This task creates DC Audio Media Objects from Build Plan.</div>"
            . "<h4>Input message parameters:</h4>"
            . "<table>"
            . "<tr> <td>" . self::TITLE_ID . ": </td> <td><input type='text' name='" . self::TITLE_ID . "' size='50' /></td> </tr>"
            . "<tr> <td>" . self::TITLE_VERSION_ID . ": </td> <td><input type='text' name='" . self::TITLE_VERSION_ID . "' size='50' /></td> </tr>"
            . "<tr> <td>" . self::CSV_FILEPATH . ": </td> <td><input type='text' name='" . self::CSV_FILEPATH . "' size='80' /></td> </tr>"
            . "</table>"
            . "<div class='buttonContainer grey'>"
            . "<input type='submit' id='submit_window' value='Submit' />"
            . "</div>";

    }


    /**
     * @throws DataNotFoundException
     * @throws Exception
     * @throws LogException
     */
    public function run() {
        $this->setRunlevel(TASK_RUNLEVEL_PROCESSING);
        $this->update();

        $this->params = TaskUtils::getInputMessageParams($this);

        $this->validateTaskParams();

        $this->processCSV();

        $this->foundMOs = array_diff($this->foundMOs, $this->createdMOs);

        $outputParams = ['created_audio_media_object_ids' => implode(",", $this->createdMOs),
            'found_audio_media_object_ids' => implode(",", $this->foundMOs),
        ];

        if ($this->invalidRows) {
            $outputParams["found_errors"] = implode(",", $this->invalidRows);
            $this->setInputMessage(TaskUtils::createInputOutputMessage(
                $outputParams,
                $this->getInputMessage()
            ));
            $this->setTaskError(implode(",", $this->invalidRows));
        } else {
            $outputMessage = TaskUtils::createInputOutputMessage(
                $outputParams,
                $this->getInputMessage()
            );
            $this->setOutputMessage($outputMessage);
            $this->setTaskSuccess(count($this->createdMOs). " MOs created, ".count($this->foundMOs)." MOs found");
        }

    }


    /**
     * @throws Exception
     */
    private function validateTaskParams() {

        if (empty($this->params[self::TITLE_ID])) {
            throw new Exception(self::TITLE_ID. ' input parameter is not set.');
        }

        if (empty($this->params[self::TITLE_VERSION_ID])) {
            throw new Exception(self::TITLE_VERSION_ID. ' input parameter is not set.');
        }

        if (empty($this->params[self::CSV_FILEPATH])) {
            throw new Exception(self::CSV_FILEPATH. ' input parameter is not set.');
        }

        if (!file_exists($this->params[self::CSV_FILEPATH])) {
            throw new Exception("File not found: ".self::CSV_FILEPATH);
        }
        $titleInfoMOs = MediaObject::getAll([MediaObject::MEDIA_CLASS_ID => MEDIA_CLASS_ID_DC_TITLE_INFO,
            MediaObject::TITLE_ID => $this->params[self::TITLE_ID]
        ]);

        if (count($titleInfoMOs) == 0) {
            throw new Exception("Title Info MO not found");
        }
        if (count($titleInfoMOs) > 1) {
            throw new Exception("More then one Title Info MO found");
        }

        $titleInfoMO = $titleInfoMOs[0];
        $this->basePath = $titleInfoMO->getAttributeByName("base_path_source");
        $this->titleABBR =  $titleInfoMO->getAttributeByName("th_title_abbr");

        $mediaClass = MediaClass::retrieveByID(MEDIA_CLASS_ID_DC_AUDIO);
        $this->basePath .= "/".$mediaClass->getSubfolderPath();


    }


    /**
     * @throws DatabaseErrorException
     * @throws Exception
     */
    private function processCSV() {

        $handle = fopen($this->params[self::CSV_FILEPATH], "r");
        $headers = true;
        $columns = null;
        $mediaObjectsData = [];
        $rowN = 1;
        while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
            if ($headers) {
                $columns = $this->validateCSV($data);
                $headers = false;
            } else {
                $moData = ["language" => "", "format" => "", "name" => ""];
                foreach ($data as $key => $value) {
                    if (isset($columns[$key])) {
                        $moData[$columns[$key]] = $value;
                    }
                }
                $mo_key = implode("_", $moData);
                $moData["csvRow"] = $rowN;
                $mediaObjectsData[$mo_key] = $moData;
            }
            $rowN++;
        }

        if (empty($this->invalidRows)) {

            foreach ($mediaObjectsData as $moData) {

                if($moData["name"] && strpos(strtoupper($moData["name"]), 'ATMOS') !== false){
                    $this->createMediaObject($moData, true);
                }
                $this->createMediaObject($moData, false);

            }
        }




    }

    /**
     * @param $moData
     * @param $isAtmos
     * @throws DatabaseErrorException
     * @throws Exception
     */
    private function createMediaObject($moData, $isAtmos) {

        if ($moData["language"]) {
            $languageID = LanguageTable::getLanguageByDCPName($moData["language"]);
            if (!$languageID) {
                $languageID = LanguageTable::getLanguageByName($moData["language"]);
            }
            if (!$languageID) {
                $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown language '{$moData["language"]}'";
                return;
            }
        } else {
            $languageID = null;
        }

        if($isAtmos == true){
            $audioConfig = "ATMOS";
        }else{
            $audioConfig = $this->getAudioConfig($moData["format"]);
        }

        if ($audioConfig != "51" && $audioConfig != "71" && $audioConfig != "ATMOS") {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown audio format '{$moData["format"]}'";
            return;
        }

        $moName = $this->titleABBR."_".$moData["language"]."_".$audioConfig;
        $moName = preg_replace('/[^A-Za-z0-9_]/', '', $moName);

        $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_AUDIO);
        $mediaObject->setName($moName."-audio");
        $mediaObject->setTitleID($this->params[self::TITLE_ID]);
        $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
        $mediaObject->setLanguageID($languageID);
        if($audioConfig == "51" || $audioConfig == "71"){
            $mediaObject->setAttributesByName(["Audio-Type" => $audioConfig, "Format" => "WAV"]);
            $path = $this->basePath."/".$audioConfig."/".LanguageTable::getLanguageDisneyCodeByID($languageID)."/";
            $path = preg_replace('/\/+/', '/', $path);
            $mediaObject->setGeneratedFilePath($path);
        }else{
            $mediaObject->setAttributesByName(["Audio-Type" => $audioConfig, "Format" => "MXF"]);
            $path = $this->basePath."/".$audioConfig."/".LanguageTable::getLanguageDisneyCodeByID($languageID)."/";
            $path = preg_replace('/\/+/', '/', $path);
            $mediaObject->setGeneratedFilePath($path);
        }

        $mediaObject->calculateSubclass();
        $result = MOUtils::checkMediaObjectUniqueness(0, $mediaObject->getMediaClassID(), $mediaObject->getTitleID(), $mediaObject->getTitleVersionID(), $mediaObject->getLanguageID(), $mediaObject->getSubclass(), null);
        if ($result === true) {
            $mediaObject->setCreatedDate(Database::asSQLDateTime(time()));
            $mediaObject->insert();
            $this->createdMOs[] = $mediaObject->getID();
        } else {
            $this->foundMOs[$result] = $result;

        }

    }


    /**
     * @param $format
     * @return mixed|string
     */
    private function getAudioConfig($format) {

        if (!$format) {
            return "";
        }
        $patterns = ["51" => "51", "5.1" => "51", "7.1" => "71", "71" => "71", "2.0" => "20", "20" => "20"];

        $result = "";
        foreach ($patterns as $key => $value) {
            $i = strpos($format, (string)$key);
            if ($i !== false) {
                $result = $value;
                break;
            }
        }
        return $result;
    }

    /**
     * @param $data
     * @return array|null
     * @throws Exception
     */
    private function validateCSV($data) {
        $columns = [];
        foreach ($data as  $i => $colHeader) {
            if (preg_match("/DCP COMPOSITION NAME/i", $colHeader)) {
                $columns["name"] = $i;
            }
            if (preg_match("/^AUDIO LANGUAGE$/i", $colHeader)) {
                $columns["language"] = $i;
            }
            if (preg_match("/^AUDIO FORMAT$/i", $colHeader)) {
                $columns["format"] = $i;
            }

        }

        foreach ([ "name" => "DCP COMPOSITION NAME", "language" => "AUDIO LANGUAGE", "format" => "AUDIO FORMAT"] as $key => $value) {
            if (!isset($columns[$key])) {
                throw new Exception("$value column not found");
            }
        }

        $columns = array_flip($columns);

        return $columns;

    }

}
