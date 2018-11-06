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


/**
 * Class GatherSubtitleMediaObjects
 */
class GatherSubtitleMediaObjects extends Task implements _task {

    const TASK_CLASS = "GatherSubtitleMediaObjects";

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
        return "<h2>Gather DC Subtitle Media Objects</h2>"
            . "<h4>Description:</h4>"
            . "<div>This task creates DC Subtitle Media Objects from Build Plan.</div>"
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

        $outputParams = ['created_subtitle_media_object_ids' => implode(",", $this->createdMOs),
            'found_subtitle_media_object_ids' => implode(",", $this->foundMOs),
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

        $this->basePath = $titleInfoMO->getAttributeByName("base_path_source");

        $mediaClass = MediaClass::retrieveByID(MEDIA_CLASS_ID_DC_SUBTITLE);
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
                $moData = ["name" => "", "standard" => "", "dim" => "", "language_ntt" => "", "language_sub1" => "", "language_sub2" => ""];
                foreach ($data as $key => $value) {
                    if (isset($columns[$key])) {
                        $moData[$columns[$key]] = $value;
                    }
                }
                if (empty($moData["language_ntt"]) && empty($moData["language_sub1"]) &&  empty($moData["language_sub2"])) {
                    $rowN++;
                    continue;
                }
                $languageID = $this->getLanguageID($moData, $rowN);
                if (!$languageID) {
                    $rowN++;
                    continue;
                }
                $moData["language_id"] = $languageID;

                $standard = $this->getDCFormat($moData["standard"]);
                if (!$standard) {
                    $this->invalidRows[] = "Row {$rowN}: unknown standard '{$moData["standard"]}'";
                    return;
                }
                $moData["standard"] = $standard;

                $type = $this->getSubtitleType($moData);
                if (!$type) {
                    $this->invalidRows[] = "Row {$rowN}: failed to determine subtitle type'";
                    return;
                }
                $moData["type"] = $type;
                if($moData["type"] == "NTT" && $moData["dim"] == "3D"){
                    $rowN++;
                    continue;
                }
                $mo_key = $moData["type"]."_". $moData["standard"]."_". $moData["dim"]."_".$moData["language_ntt"]."_". $moData["language_sub1"]."_". $moData["language_sub2"];
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

        $lng = $moData["language_ntt"].$moData["language_sub1"].$moData["language_sub2"];
        $lng = preg_replace('/[^A-Za-z]/', '', $lng);
        $moName = $this->titleABBR."_".$moData['dim']."_".$lng."-".$moData["type"]."_".$moData["standard"];
        $moName = preg_replace('/[^A-Za-z0-9_\-]/', '', $moName);

        $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_SUBTITLE);
        $mediaObject->setName($moName);
        $mediaObject->setTitleID($this->params[self::TITLE_ID]);
        $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
        $mediaObject->setLanguageID($moData["language_id"]);

        $mediaClass = MediaClass::retrieveByID(MEDIA_CLASS_ID_DC_SUBTITLE);
        $getSubfolderpathVar1 = $mediaClass->getSubfolderpathVar1();
        $getSubfolderpathVar2 = $mediaClass->getSubfolderpathVar2();
        if (in_array($moData["language_id"], [1023,1024,1080,1031,1000,1057,1055,1056,2042])) {
            $path = $this->basePath."/".$moData["dim"]."/".$moData["standard"]."/".$moData["type"]."/".$getSubfolderpathVar1."/".LanguageTable::getLanguageDisneyCodeByID($moData["language_id"])."/";
        } elseif ($moData["type"] == "OCAP" || $moData["type"] == "CCAP") {
            $path = $this->basePath."/".$moData["dim"]."/".$moData["standard"]."/".$moData["type"]."/".LanguageTable::getLanguageDisneyCodeByID($moData["language_id"])."/";
        } else {
            $path = $this->basePath."/".$moData["dim"]."/".$moData["standard"]."/".$moData["type"]."/".$getSubfolderpathVar2."/".LanguageTable::getLanguageDisneyCodeByID($moData["language_id"])."/";
        }

        $path = preg_replace('/\/+/', '/', $path);
        $mediaObject->setGeneratedFilePath($path);

        if(strpos($path,"PNG") === false){
            $mediaObject->setAttributesByName(["DC_Format" => $moData["standard"], "Type" => $moData["type"], "Dimension" => $moData['dim'], "Resource_Type" => "TTF"]);
        }else{
            $mediaObject->setAttributesByName(["DC_Format" => $moData["standard"], "Type" => $moData["type"], "Dimension" => $moData['dim'], "Resource_Type" => "PNG"]);
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
     * @param $moData
     * @param $rowN
     * @return int|null
     * @throws DatabaseErrorException
     */
    private function getLanguageID($moData, $rowN) {
        $languageID = null;

        if ($moData["language_ntt"]) {
            $languageID = LanguageTable::getLanguageByDCPSubtitleName($moData["language_ntt"]);
            if (!$languageID) {
                $languageID = LanguageTable::getLanguageByName($moData["language_ntt"]);
            }
            if (!$languageID) {
                $this->invalidRows[] = "Row {$rowN}: unknown language '".$moData["language_ntt"]."'";
            }
        } else {
            $languageID = LanguageTable::getLanguageByDCPSubtitleName($moData["language_sub1"].$moData["language_sub2"]);
            if (!$languageID) {
                $languageID = LanguageTable::getLanguageByName($moData["language_sub1"].$moData["language_sub2"]);
            }
            if (!$languageID) {
                $this->invalidRows[] = "Row {$rowN}: unknown language '".$moData["language_sub1"].$moData["language_sub2"]."'";
            }
        }

        return $languageID;

    }
    /**
     * @param $moData
     * @return null|string
     */
    private function getSubtitleType($moData) {
        /*  <Attribute>
          <Input Type="Select" Name="Type" Required="1" Unique="1">
            <Option selected="1" Value="STT">STT</Option>
            <Option Value="NTT">NTT</Option>
            <Option Value="OCAP">OCAP</Option>
            <Option Value="CCAP">CCAP</Option>
        </Input></Attribute>
      */
        $type = null;
        if (preg_match("/(-|_)(CCAP|OCAP)(-|_)/i", $moData["name"], $matches) ) {
            $type = $matches[2];
        } elseif ($moData["language_ntt"]) {
            $type = "NTT";
        } elseif ($moData["language_sub1"] || $moData["language_sub2"] ){
            $type = "STT";
        }

        return $type;
    }

    /**
     * @param $format
     * @return mixed|string
     */
    private function getDCFormat($format) {

        if (!$format) {
            return "";
        }
        $patterns = ["IOP" => "IOP", "SMPTE" => "SMPTE", "Interop" => "IOP"];

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
        $dataCols = ["name" => "DCP COMPOSITION NAME",
                    "standard" => '^STANDARD$',
                    "dim" => '^DIM.$',
                    "language_ntt" => '^NTT \/ FORCED NARRATIVE LANGUAGE$',
                    "language_sub1" => '^SUBTITLE\/CAPTION LANGUAGE #1$',
                    "language_sub2" => '^SUBTITLE LANGUAGE #2$'];

        foreach ($data as  $i => $colHeader) {
            foreach ($dataCols as $key => $name) {
                if (preg_match("/{$name}/i", $colHeader)) {
                    $columns[$key] = $i;
                }
            }
        }

        foreach ($dataCols as $key => $value) {
            if (!isset($columns[$key])) {
                $colName = preg_replace('/[^A-Za-z0-9\s\.\/\#]/', '', $value);
                throw new Exception("$colName column not found");
            }
        }

        $columns = array_flip($columns);

        return $columns;

    }

}
