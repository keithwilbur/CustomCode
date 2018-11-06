<?php

require_once (PATH_TO_INC . "/entities/Task.php");
require_once (PATH_TO_INC . "/tasks/_task.php");
require_once (PATH_TO_INC . "/system/TaskFactory.php");
require_once (PATH_TO_INC . "/entities/MediaObject.php");
require_once (PATH_TO_INC . "/entities/MediaClass.php");
require_once PATH_TO_INC . "/entities/MediaObjectInputs.php";
require_once (PATH_TO_INC . "/entities/Title.php");
require_once (PATH_TO_INC . "/entities/Language.php");
require_once (PATH_TO_INC . "/lib/MOUtils.php");


class GatherAncillaryAudioMediaObjects extends Task implements _task {

    const TASK_CLASS = "GatherAncillaryAudioMediaObjects";

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
        return "<h2>Gather DC Ancillary Audio Media Objects</h2>"
            . "<h4>Description:</h4>"
            . "<div>This task creates DC Ancillary Audio Media Objects from Build Plan.</div>"
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

        $outputParams = ['created_ancillary_audio_media_object_ids' => implode(",", $this->createdMOs),
            'found_ancillary_audio_media_object_ids' => implode(",", $this->foundMOs),
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

        $mediaClass = MediaClass::retrieveByID(MEDIA_CLASS_ID_DC_ANCILARY_AUDIO);
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
                $moData = ["name" => "", "language" => ""];
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
                $this->createMediaObject($moData);
            }
        }

    }

    /**
     * @param $moData
     * @throws DatabaseErrorException
     * @throws Exception
     */
    private function createMediaObject($moData) {

        if (preg_match("/(51|71)-([A-Za-z0-9\-]+)/", $moData["name"], $matches)) {
            if($matches[2] == "ATMOS"){
                return;
            }
            $audioConfig = preg_replace("/(ATMOS|-ATMOS)/i","",$matches[2]);
        } else {
           return;
        }

        $possibleCfg = ["HI", "VI", "DBOX"];
        $audioConfigs = explode("-", trim($audioConfig,'-'));

        foreach ($audioConfigs as $ac) {
            if (!in_array(strtoupper($ac), $possibleCfg)) {
                $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown Ancillary Audio Type  '{$ac}'";
                return;
            }
        }

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


        foreach ($audioConfigs as $ac) {

            $acLngID = $languageID;
            $disneyCode = LanguageTable::getLanguageDisneyCodeByID($languageID);
            $acLanguage = "_".$moData["language"];
            if (strtoupper($ac) == "DBOX") {
                $acLngID = LANGUAGE_ALL_ID;
                $acLanguage = "";
                //$disneyCode = strtoupper($ac);
                $disneyCode = "ALL";
            }

            if (isset($this->foundMOs[$acLngID."_".$ac])) {
                continue;
            }

            $moName = $this->titleABBR.$acLanguage."_".$ac;
            $moName = preg_replace('/[^A-Za-z0-9_]/', '', $moName);

            $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_ANCILARY_AUDIO);
            $mediaObject->setName($moName."-ancAudio");
            $mediaObject->setTitleID($this->params[self::TITLE_ID]);
            $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
            $mediaObject->setLanguageID($acLngID);
            $mediaObject->setAttributesByName(["Audio-Type" => strtoupper($ac)]);

            $path = $this->basePath."/".strtoupper($ac)."/".$disneyCode."/";
            $path = preg_replace('/\/+/', '/', $path);
            $mediaObject->setGeneratedFilePath($path);

            $mediaObject->calculateSubclass();
            $result = MOUtils::checkMediaObjectUniqueness(0, $mediaObject->getMediaClassID(), $mediaObject->getTitleID(), $mediaObject->getTitleVersionID(), $mediaObject->getLanguageID(), $mediaObject->getSubclass(), null);
            if ($result === true) {
                $mediaObject->setCreatedDate(Database::asSQLDateTime(time()));
                $mediaObject->insert();
                $this->createdMOs[] = $mediaObject->getID();
            } else {
                $this->foundMOs[$acLngID."_".$ac] = $result;

            }


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
        }


        foreach ([ "name" => "DCP COMPOSITION NAME", "language" => "AUDIO LANGUAGE"] as $key => $value) {
            if (!isset($columns[$key])) {
                throw new Exception("$value column not found");
            }
        }

        $columns = array_flip($columns);

        return $columns;

    }

}
