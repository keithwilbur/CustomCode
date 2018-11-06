<?php

require_once (PATH_TO_INC . "/entities/Task.php");
require_once (PATH_TO_INC . "/tasks/_task.php");
require_once (PATH_TO_INC . "/system/TaskFactory.php");
require_once (PATH_TO_INC . "/entities/MediaObject.php");
require_once (PATH_TO_INC . "/entities/MediaClass.php");
require_once PATH_TO_INC . "/entities/MediaObjectInputs.php";
require_once (PATH_TO_INC . "/entities/Title.php");
require_once (PATH_TO_INC . "/entities/Language.php");
require_once (PATH_TO_INC . "/entities/Territory.php");
require_once (PATH_TO_INC . "/lib/MOUtils.php");


class GatherPictureMediaObjects extends Task implements _task {

    const TASK_CLASS = "GatherPictureMediaObjects";

    const CSV_FILEPATH = "csv_filepath";
    const TITLE_ID = "title_id";
    const TITLE_VERSION_ID = "title_version_id";
    const STUDIO_PROFILE = "studio_profile";
    const SOURCE_TYPE = "source_type";


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
        return "<h2>Gather DC Picture Media Objects</h2>"
            . "<h4>Description:</h4>"
            . "<div>This task creates DC Picture Media Objects from Build Plan.</div>"
            . "<h4>Input message parameters:</h4>"
            . "<table>"
            . "<tr> <td>" . self::TITLE_ID . ": </td> <td><input type='text' name='" . self::TITLE_ID . "' size='50' /></td> </tr>"
            . "<tr> <td>" . self::TITLE_VERSION_ID . ": </td> <td><input type='text' name='" . self::TITLE_VERSION_ID . "' size='50' /></td> </tr>"
            . "<tr> <td>" . self::CSV_FILEPATH . ": </td> <td><input type='text' name='" . self::CSV_FILEPATH . "' size='80' /></td> </tr>"
            . "<tr><td>".self::STUDIO_PROFILE." </td><td><select name='".self::STUDIO_PROFILE."'>"
            . "<option value='".ADCP_BUILD_PLAN_STUDIO_PROFILE_DISNEY."' selected='selected'>".ADCP_BUILD_PLAN_STUDIO_PROFILE_DISNEY."</option>"
            . "<option value='".ADCP_BUILD_PLAN_STUDIO_PROFILE_PIXAR."'>".ADCP_BUILD_PLAN_STUDIO_PROFILE_PIXAR."</option>"
            . "</select></td></tr>"
            . "<tr><td>".self::SOURCE_TYPE." </td><td><select name='".self::SOURCE_TYPE."'>"
            . "<option value='".ADCP_BUILD_PLAN_STUDIO_SOURCETYPE_DCP."' selected='selected'>".ADCP_BUILD_PLAN_STUDIO_SOURCETYPE_DCP."</option>"
            . "<option value='".ADCP_BUILD_PLAN_STUDIO_SOURCETYPE_DCDM."'>".ADCP_BUILD_PLAN_STUDIO_SOURCETYPE_DCDM."</option>"
            . "</select></td></tr>"
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

        $outputParams = ['created_picture_media_object_ids' => implode(",", $this->createdMOs),
            'found_picture_media_object_ids' => implode(",", $this->foundMOs),
        ];

        if ($this->invalidRows) {
            $outputParams["found_picture_errors"] = implode(",", $this->invalidRows);
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

        $mediaClass = MediaClass::retrieveByID(MEDIA_CLASS_ID_DC_PICTURE) ;
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
                $moData = ["name" => "", "territory" => "", "picture" => "", "dim" => "", "standard" => "", "language" => ""];
                foreach ($data as $key => $value) {
                    if (isset($columns[$key])) {
                        $moData[$columns[$key]] = $value;
                    }
                }

                $languageID = $this->getLanguageID($moData);
                if (!$languageID) {
                    $this->invalidRows[] = "Row {$rowN}: unknown language '{$moData["language"]}'";
                    $rowN++;
                    continue;
                }
                $moData["languageID"] = $languageID;
                if ($languageID == LANGUAGE_ALL_ID) {
                    $moData["language"] = "English";
                }

                $moData["resolution"] = $this->getMOResolution($moData["name"]);

                $mo_key = $moData["languageID"]."_". $moData["dim"]."_". $moData["standard"];
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

        $standard = $this->getDCFormat($moData["standard"]);
        if (!$standard) {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown standard '{$moData["standard"]}'";
            return;
        }

        $moName = $this->titleABBR."_".$moData["language"]."_".$this->getTerritory($moData["territory"], $moData["languageID"])."_". $moData["dim"]."_". $standard;
        $moName = preg_replace('/[^A-Za-z0-9_]/', '', $moName);

        $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_PICTURE);
        $mediaObject->setName($moName."-picture");
        $mediaObject->setTitleID($this->params[self::TITLE_ID]);
        $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
        $mediaObject->setLanguageID($moData["languageID"]);
        $mediaObject->setAttributesByName(["DC_Format" => $standard, "Dimension" => $moData["dim"], "Content_Kind" => "feature", "Resolution" => $moData["resolution"]]);

        $mediaClass = MediaClass::retrieveByID(MEDIA_CLASS_ID_DC_PICTURE) ;
        $getSubfolderpathVar1 = $mediaClass->getSubfolderpathVar1();
        $path = $this->basePath."/".$moData["dim"]."/".$standard."/".$getSubfolderpathVar1."/".LanguageTable::getLanguageDisneyCodeByID($moData["languageID"])."/";
        $path = preg_replace('/\/+/', '/', $path);
        $mediaObject->setGeneratedFilePath($path);

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
     * @return int|null
     * @throws DatabaseErrorException
     */
    private function getLanguageID ($moData) {
        $languageID = null;

        if ($this->params[self::STUDIO_PROFILE] == ADCP_BUILD_PLAN_STUDIO_PROFILE_DISNEY ) {
            return LANGUAGE_ALL_ID;
        }

        $pattern = "/^(Domestic|Localized)/i";
        if (preg_match($pattern, $moData["picture"])) {
            $languageID = LanguageTable::getLanguageByDCPName($moData["language"]);
            if (!$languageID) {
                $languageID = LanguageTable::getLanguageByName($moData["language"]);
            }
        } else {
            $languageID = LANGUAGE_ALL_ID;
        }

        return $languageID;
    }

    /**
     * @param $format
     * @return mixed|string
     */
    private function getDCFormat($format) {

        /*  <Attribute>
            <Input Type="Select" Name="DC_Format" Required="1" Unique="1">
              <Option Value="IOP">IOP</Option>
              <Option Value="SMPTE">SMPTE</Option>
            </Input>
          </Attribute>*/

        $result = "";
        if($this->params[self::SOURCE_TYPE] == 'DCDM'){
            $result = $this->params[self::SOURCE_TYPE];
            return $result;
        }

        if (!$format) {
            return "";
        }
        $patterns = ["IOP" => "IOP", "SMPTE" => "SMPTE", "Interop" => "IOP"];

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
     * @param $territoryName
     * @param $languageID
     * @return null|string
     * @throws DatabaseErrorException
     */
    private function getTerritory($territoryName, $languageID) {
        if ($languageID == LANGUAGE_ALL_ID) {
            return "OV";
        }

        if (preg_match("/^Original Version/i", $territoryName)) {
            return "OV";
        }

        $territory = Territory::retrieveByName($territoryName);
        if ($territory) {
            return $territory->getName_abbr_two();
        } else {
            return $territoryName;
        }

    }

    /**
     * @param $name
     * @return null
     */
    private function getMOResolution($name) {
        $result = null;

        if (preg_match("/(51|71)([A-Za-z0-9\-]*)_([A-Za-z0-9]+)_/", $name, $matches)) {
            $result = $matches[3];
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
        $dataCols = ["name" => "DCP COMPOSITION NAME", "territory" => '^TERRITORY \(DDSS\)$', "picture" => '^PICTURE$', "dim" => '^DIM.$', "standard" => '^STANDARD$', "language" => '^AUDIO LANGUAGE$'];
        foreach ($data as  $i => $colHeader) {
            foreach ($dataCols as $key => $name) {
                if (preg_match("/{$name}/i", $colHeader)) {
                    $columns[$key] = $i;
                }
            }
        }

        foreach ($dataCols as $key => $value) {
            if (!isset($columns[$key])) {
                $colName = preg_replace('/[^A-Za-z0-9\s\.]/', '', $value);
                throw new Exception("$colName column not found");
            }
        }

        $columns = array_flip($columns);

        return $columns;

    }

}
