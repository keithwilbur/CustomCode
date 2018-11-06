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


/**
 * Class GatherCompositeAudioMediaObjects
 */
class GatherCompositeAudioMediaObjects extends Task implements _task {

    const TASK_CLASS = "GatherCompositeAudioMediaObjects";

    const CSV_FILEPATH = "csv_filepath";
    const TITLE_ID = "title_id";
    const TITLE_VERSION_ID = "title_version_id";


    private $params;
    private $createdMOs = [];
    private $foundMOs = [];
    private $invalidRows = [];
    private $titleABBR;



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
        return "<h2>Gather DC Comosite Audio Media Objects</h2>"
            . "<h4>Description:</h4>"
            . "<div>This task creates DC Comosite Audio Media Objects from Build Plan.</div>"
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

        $outputParams = ['created_composite_audio_media_object_ids' => implode(",", $this->createdMOs),
            'found_composite_audio_media_object_ids' => implode(",", $this->foundMOs),
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
        $this->titleABBR =  $titleInfoMO->getAttributeByName("th_title_abbr");


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
                $languageID = null;
                if($moData["language"]) {
                    $languageID = LanguageTable::getLanguageByDCPName($moData["language"]);
                    if (!$languageID) {
                        $languageID = LanguageTable::getLanguageByName($moData["language"]);
                    }
                    if (!$languageID) {
                        $this->invalidRows[] = "Row {$rowN}: unknown language '{$moData["language"]}'";
                        $rowN++;
                        continue;
                    }
                    $moData["languageID"] = $languageID;
                }

                $inputSubclass = $this->getSubclass($moData["name"], $rowN);
                if (!$inputSubclass) {
                    $rowN++;
                    continue;
                }
                $moData["input_subclass"] = $inputSubclass;
                //DELTA-2520
                if(preg_match("/IOP/i", $moData["name"])){
                    $DC_Format = "IOP";
                }elseif(preg_match("/Atmos/i", $moData["name"]) && preg_match("/SMPTE/i", $moData["name"])){
                    $DC_Format = "SMPTE20";
                }elseif(preg_match("/SMPTE/i", $moData["name"])){
                    $DC_Format = "SMPTE21";
                }else{
                    $DC_Format = null;
                }
                $mo_key = implode("-", $inputSubclass)."_".$moData["languageID"]."_".$DC_Format;
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

        $parentsData = [];

        foreach ($moData["input_subclass"] as $subclass) {
            $mediaClassID = MEDIA_CLASS_ID_DC_ANCILARY_AUDIO;
            $mediaClassName = "DC Ancillary Audio";
            if ($subclass == "51" || $subclass == "71") {
                $mediaClassID = MEDIA_CLASS_ID_DC_AUDIO;
                $mediaClassName = "DC Audio";
                $subclass .= "-WAV";
            }
            $lngID = $moData["languageID"];
            if ($subclass == "DBOX") {
                $lngID = LANGUAGE_ALL_ID;
            }
            $mo = MediaObject::getMODataByClassVersionLngSubclass($mediaClassID, $this->params[self::TITLE_VERSION_ID], $lngID, $subclass);
            if (!$mo) {
                //Row 39: failed to find DC Audio 71 'Italian'
                $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find $mediaClassName $subclass '{$moData["language"]}'";
                return;
            }
            $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $mo[MediaObject::ID],
                MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
                MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_AUDIO
            ];
        }


        $lng = preg_replace('/[^A-Za-z]/', "", $moData["language"]);
        $moName = $this->titleABBR."_".$lng."_".implode("-", $moData["input_subclass"])."-compAud";
        $moName = preg_replace('/[^A-Za-z0-9_\-]/', '', $moName);


        $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_COMPOSITE_AUDIO);
        $mediaObject->setName($moName);
        $mediaObject->setTitleID($this->params[self::TITLE_ID]);
        $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
        $mediaObject->setLanguageID($moData["languageID"]);

        $mediaObject->setParents($parentsData);

        if(preg_match("/IOP/i", $moData["name"])){
            $DC_Format = "IOP";
        }elseif(preg_match("/Atmos/i", $moData["name"]) && preg_match("/SMPTE/i", $moData["name"])){
            $DC_Format = "SMPTE20";
        }elseif(preg_match("/SMPTE/i", $moData["name"])){
            $DC_Format = "SMPTE21";
        }else{
            $DC_Format = null;
        }
        $mediaObject->setAttributesByName(["DC_Format" => $DC_Format]);

        $mediaObject->calculateSubclass();
        $result = MOUtils::checkMediaObjectUniqueness(0, $mediaObject->getMediaClassID(), $mediaObject->getTitleID(), $mediaObject->getTitleVersionID(), $mediaObject->getLanguageID(), $mediaObject->getSubclass(), null);
        if ($result === true) {
            $mediaObject->setCreatedDate(Database::asSQLDateTime(time()));
            $mediaObject->insert();
            MediaObjectInputs::updateMediaObjectParents($mediaObject->getID(), $parentsData);
            $this->createdMOs[] = $mediaObject->getID();
        } else {
            $this->foundMOs[$result] = $result;
        }

    }

    /**
     * @param $name
     * @param $nRow
     * @return array|null
     */
    private function getSubclass($name, $nRow) {
        $result = [];
        if (preg_match("/(51|71)-([A-Za-z0-9\-]+)/", $name, $matches)) {
            if($matches[2] == "ATMOS"){
                return null;
            }
            $result[] = $matches[1];
            $audioConfig = preg_replace("/(ATMOS|-ATMOS)/i","",$matches[2]);
        }elseif(preg_match("/(51|71)+/", $name, $matches)){
            //DELTA-2520 create a composite audio even if there is one input.
            $result[] = $matches[1];
            return $result;
        } else {
            // it's not a composite audio
            return null;
        }

        $possibleCfg = ["HI", "VI", "DBOX"];
        $audioConfigs = explode("-", $audioConfig = trim($audioConfig,'-'));

        foreach ($audioConfigs as $ac) {
            if (!in_array(strtoupper($ac), $possibleCfg)) {
                $this->invalidRows[] = "Row {$nRow}: unknown Audio Type  '{$ac}'";
                return null;
            }
            $result[] = strtoupper($ac);
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
        $dataCols = ["name" => "DCP COMPOSITION NAME",  "language" => '^AUDIO LANGUAGE$'];
        foreach ($data as  $i => $colHeader) {
            foreach ($dataCols as $key => $name) {
                if (preg_match("/{$name}/i", $colHeader)) {
                    $columns[$key] = $i;
                }
            }
        }

        foreach ($dataCols as $key => $value) {
            if (!isset($columns[$key])) {
                $colName = preg_replace('/[^A-Za-z0-9\s]/', '', $value);
                throw new Exception("$colName column not found");
            }
        }

        $columns = array_flip($columns);

        return $columns;

    }

}
