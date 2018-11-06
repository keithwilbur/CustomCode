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


class GatherPictureReelMediaObjects extends Task implements _task {

    const TASK_CLASS = "GatherPictureReelMediaObjects";

    const CSV_FILEPATH = "csv_filepath";
    const TITLE_ID = "title_id";
    const TITLE_VERSION_ID = "title_version_id";
    const STUDIO_PROFILE = "studio_profile";


    private $params;
    private $createdMOs = [];
    private $foundMOs = [];
    private $invalidRows = [];
    private $titleABBR;
    private $reelsCount;
    private $basePath;
    private $territoryList;


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
        return "<h2>Gather DC Picture Reel or DC External CPL Media Objects</h2>"
            . "<h4>Description:</h4>"
            . "<div>This task creates DC Picture Reel Media Objects from Build Plan for Pixar and DC External CPL Media Objects for Disney Studios.</div>"
            . "<h4>Input message parameters:</h4>"
            . "<table>"
            . "<tr> <td>" . self::TITLE_ID . ": </td> <td><input type='text' name='" . self::TITLE_ID . "' size='50' /></td> </tr>"
            . "<tr> <td>" . self::TITLE_VERSION_ID . ": </td> <td><input type='text' name='" . self::TITLE_VERSION_ID . "' size='50' /></td> </tr>"
            . "<tr> <td>" . self::CSV_FILEPATH . ": </td> <td><input type='text' name='" . self::CSV_FILEPATH . "' size='80' /></td> </tr>"
            . "<tr><td>".self::STUDIO_PROFILE." </td><td><select name='".self::STUDIO_PROFILE."'>"
            . "<option value='".ADCP_BUILD_PLAN_STUDIO_PROFILE_DISNEY."' selected='selected'>".ADCP_BUILD_PLAN_STUDIO_PROFILE_DISNEY."</option>"
            . "<option value='".ADCP_BUILD_PLAN_STUDIO_PROFILE_PIXAR."'>".ADCP_BUILD_PLAN_STUDIO_PROFILE_PIXAR."</option>"
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


        if ($this->params[self::STUDIO_PROFILE] == ADCP_BUILD_PLAN_STUDIO_PROFILE_DISNEY ) {
            $mediaClass = MediaClass::retrieveByID(MEDIA_CLASS_ID_DC_EXTERNAL_OV_CPL);
            $this->basePath .= "/".$mediaClass->getSubfolderPath();
            $this->processCSVExternalCPL();
            $this->foundMOs = array_diff($this->foundMOs, $this->createdMOs);
            $outputParams = ['created_external_cpl_media_object_ids' => implode(",", $this->createdMOs),
                'found_external_cpl_media_object_ids' => implode(",", $this->foundMOs),
            ];

        } else {
            $mediaClass = MediaClass::retrieveByID(MEDIA_CLASS_ID_DC_PICTURE_REEL);
            $this->basePath .= "/".$mediaClass->getSubfolderPath();
            $this->processCSVPictureReel();
            $this->foundMOs = array_diff($this->foundMOs, $this->createdMOs);
            $outputParams = ['created_picture_reel_media_object_ids' => implode(",", $this->createdMOs),
                'found_picture_reel_media_object_ids' => implode(",", $this->foundMOs),
            ];

        }


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
        $this->reelsCount = $titleInfoMO->getAttributeByName("Reels_Count");

        $this->basePath = $titleInfoMO->getAttributeByName("base_path_source");




    }


    /**
     * @throws DataNotFoundException
     * @throws DatabaseErrorException
     * @throws Exception
     * @throws IllegalArgumentException
     */
    private function processCSVPictureReel() {

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
                $moData = ["name" => "", "picture" => "", "dim" => "", "standard" => "", "language" => ""];
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
                        return;
                    }
                    $moData["languageID"] = $languageID;
                }
                $standard = $this->getDCFormat($moData["standard"]);
                if (!$standard) {
                    $this->invalidRows[] = "Row {$rowN}: unknown standard '{$moData["standard"]}'";
                    return;
                }
                $moData["standard"] = $standard;

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

        $moName = $this->titleABBR."_".$moData["language"]."_Reel_".$moData["standard"];
        $moName = preg_replace('/[^A-Za-z0-9_]/', '', $moName);

        $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_PICTURE_REEL);
        $mediaObject->setName($moName);
        $mediaObject->setTitleID($this->params[self::TITLE_ID]);
        $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
        $mediaObject->setLanguageID($moData["languageID"]);

        $attrs = ["DC_Format" => $moData["standard"], "Dimension" => $moData["dim"], "Reel_Position" => $this->reelsCount];
        if (preg_match("/^INTL-OV\/Neutral with Localized Final Reel \/ Dub Cards/i", $moData["picture"]) ) {
            $attrs["Content_Kind"] = "DUBCARD";
        }
        $mediaObject->setAttributesByName($attrs);

        $mediaClass = MediaClass::retrieveByID(MEDIA_CLASS_ID_DC_PICTURE_REEL);
        $getSubfolderpathVar1 = $mediaClass->getSubfolderpathVar1();
        $path = $this->basePath."/".$moData["dim"]."/".$moData["standard"]."/".$getSubfolderpathVar1."/".LanguageTable::getLanguageDisneyCodeByID($moData["languageID"])."/";
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
           /* $mo = MediaObject::retrieveByID($result);
            $mo->setGeneratedFilePath($path);
            $mo->update();*/
        }

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
        $dataCols = ["name" => "DCP COMPOSITION NAME", "picture" => '^PICTURE$', "dim" => '^DIM.$', "standard" => '^STANDARD$', "language" => '^AUDIO LANGUAGE$'];
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

    /**
     * @throws DataNotFoundException
     * @throws DatabaseErrorException
     * @throws Exception
     * @throws IllegalArgumentException
     */
    private function processCSVExternalCPL() {

        $handle = fopen($this->params[self::CSV_FILEPATH], "r");
        $headers = true;
        $columns = null;
        $mediaObjectsData = [];
        $rowN = 1;
        while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
            if ($headers) {
                $columns = $this->validateCSVExternalCPL($data);
                $headers = false;
            } else {
                $moData = [];
                foreach ($data as $key => $value) {
                    if (isset($columns[$key])) {
                        $moData[$columns[$key]] = $value;
                    }
                }

                if (!preg_match("/Dub Cards/i", $moData["picture"])) {
                    $rowN++;
                    continue;
                }

                $languageID = null;
                if($moData["language"]) {
                    $languageID = LanguageTable::getLanguageByDCPName($moData["language"]);
                    if (!$languageID) {
                        $languageID = LanguageTable::getLanguageByName($moData["language"]);
                    }
                    if (!$languageID) {
                        $this->invalidRows[] = "Row {$rowN}: unknown language '{$moData["language"]}'";
                        return;
                    }
                    $moData["languageID"] = $languageID;
                }
                $standard = $this->getDCFormat($moData["standard"]);
                if (!$standard) {
                    $this->invalidRows[] = "Row {$rowN}: unknown standard '{$moData["standard"]}'";
                    return;
                }
                $moData["standard"] = $standard;

              /*  if (preg_match("/_51/i", $moData["name"])) {
                    $moData["audio_config"] = "51";
                } elseif (preg_match("/_71/i", $moData["name"])) {
                    $moData["audio_config"] = "71";
                } else {
                    $this->invalidRows[] = "Row {$rowN}: failed to determine Audio Config";
                    return;
                }*/

              //  $moData["subtitle_language"] = $this->getSubtitleLanguage($moData["name"]);
                $moData["audio_language"] = $this->getAudioLanguage($moData["name"]);

                $mo_key = $moData["languageID"]."_". $moData["standard"]."_".$moData["dim"]."_". $moData["audio_language"];
                $moData["csvRow"] = $rowN;
                $mediaObjectsData[$mo_key] = $moData;
            }
            $rowN++;
        }

        if (empty($this->invalidRows)) {
            $ovMediaClass = MediaClass::retrieveByID(MEDIA_CLASS_ID_DC_OV_CPL) ;
            $ovCplMCAttributes = MediaClass::getAttributesData($ovMediaClass->getAttributes());
            // get a list of possible territories. Should be the same for OV and VF
            foreach($ovCplMCAttributes as $attrData) {
                if ($attrData["name"] == "Territory") {
                    $this->territoryList = $attrData["options"];
                    break;
                }
            }

            foreach ($mediaObjectsData as $moData) {
                $this->createExternalCPLMediaObject($moData);
            }
        }

    }

    /**
     * @param $moData
     * @throws DatabaseErrorException
     * @throws Exception
     */
    private function createExternalCPLMediaObject($moData) {

        $moName = $this->titleABBR."_".$moData["language"]."_Dubcard_".$moData["standard"];
        $moName = preg_replace('/[^A-Za-z0-9_]/', '', $moName);

        $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_EXTERNAL_OV_CPL);
        $mediaObject->setName($moName);
        $mediaObject->setTitleID($this->params[self::TITLE_ID]);
        $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
        $mediaObject->setLanguageID($moData["languageID"]);

        $attrs = ["Audio_Config" => "51", // $moData["audio_config"],
                "DC_Format" => $moData["standard"],
                "Dimension" => $moData["dim"],
                "Subtitle_Language" => "XX", // $moData["subtitle_language"],
                "Reels_Count" => 1,
                "Movie_Title" => $this->titleABBR."DUBCARD",
                "Audio_Language" => $moData["audio_language"],
                "Territory" => $this->getTerritory($moData["name"]),
                "Resolution" => $this->getMOResolution($moData["name"]),
                "Content_Kind" => "dubcard",
                "Audio-HI" => "false",
                "Audio-VI" => "false",
                "Dbox" => "false",
                "Atmos" => "false"

        ];
        $attrs = $this->addAudioAttrs($moData["name"], $attrs);

        $mediaObject->setAttributesByName($attrs);
        $mediaClass = MediaClass::retrieveByID(MEDIA_CLASS_ID_DC_EXTERNAL_OV_CPL);
        $getSubfolderpathVar2 = $mediaClass->getSubfolderpathVar2();
        $path = $this->basePath."/".$moData["dim"]."/". $moData["standard"]. "/".$getSubfolderpathVar2."/".LanguageTable::getLanguageDisneyCodeByID($moData["languageID"])."/";
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
        /*     $mo = MediaObject::retrieveByID($result);
             $mo->setGeneratedFilePath($path);
             $mo->update();*/
        }

    }

    /**
     * @param $data
     * @return array|null
     * @throws Exception
     */
    private function validateCSVExternalCPL($data) {
        $columns = [];
        $dataCols = ["name" => "DCP COMPOSITION NAME", "picture" => '^PICTURE$', "dim" => '^DIM.$',  "standard" => '^STANDARD$', "language" => '^AUDIO LANGUAGE$'];
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

    /**
     * @param $name
     * @return string
     */
    private function getSubtitleLanguage($name) {
        $lng = "";
        if (preg_match("/_([A-Za-z\-]+)_([A-Za-z\-]+)_(51|71)/i", $name, $matches) ) {
            $s = $matches[1];
            $a = explode("-", $s, 2);
            $lng = $a[1];
        }

        return $lng;
    }

    /**
     * @param $name
     * @return string
     */
    private function getAudioLanguage($name) {
        $lng = "";
        if (preg_match("/_([A-Za-z\-]+)_([A-Za-z\-]+)_(51|71)/i", $name, $matches) ) {
            $s = $matches[1];
            $a = explode("-", $s, 2);
            $lng = $a[0];
        }

        return $lng;
    }

    /**
     * @param $name
     * @return string
     */
    private function getTerritory ($name) {

        if (preg_match("/_US-PG_/i", $name)) {
            $terr = "US";
        } elseif (preg_match("/_LAS_/i", $name)) {
            $terr = "MX";
        } elseif (preg_match("/_OV_/i", $name)) {
            $terr = "GB";
        } else {
            $pattern = implode("|", array_keys($this->territoryList));
            if (preg_match("/_($pattern)_/i", $name, $matches)) {
                $terr = strtoupper($matches[1]);
            }
        }

        return $terr;

    }

    /**
     * @param $name
     * @param $attrs
     * @return mixed
     */
    private function addAudioAttrs($name, $attrs) {
        $result = [];
        if (preg_match("/(51|71)-([A-Za-z0-9\-]+)/", $name, $matches)) {
            $result[] = $matches[1];
            $audioConfig = $matches[2];
        } else {
            return $attrs;
        }

        $possibleCfg = ["HI" => "Audio-HI", "VI" => "Audio-VI", "ATMOS" => "Atmos", "DBOX" => "Dbox"];
        $audioConfigs = explode("-", $audioConfig);
        $audioConfigs = array_map("strtoupper", $audioConfigs);

        foreach ($possibleCfg as $ac => $attr) {
            if (in_array($ac, $audioConfigs)) {
                $attrs[$attr] = "true";
            } else {
                $attrs[$attr] = "false";
            }
        }

        return $attrs;
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

}
