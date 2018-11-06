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
 * Class GatherOVVFCPLMediaObjects
 */
class GatherCPL_DPMediaObjects extends Task implements _task {

    const TASK_CLASS = "GatherCPL_DPMediaObjects";

    const CSV_FILEPATH = "csv_filepath";
    const TITLE_ID = "title_id";
    const TITLE_VERSION_ID = "title_version_id";
    const STUDIO_PROFILE = "studio_profile";
    const SOURCE_TYPE = "source_type";
    const DCP_VENDOR = "dcp_vendor";

    private $params;
    private $dcOVCPL = [];
    private $dcOVCPLLanguageID = [];
    private $createdMOs = [];
    private $createdVFMOs = [];
    private $foundMOs = [];
    private $foundVFMOs = [];
    private $dcPackages = [];
    private $createdDPMOs = [];
    private $foundDPMOs = [];
    private $createdDPWMOs = [];
    private $foundDPWMOs = [];
    private $createdCompositeMOs = [];
    private $foundCompositeMOs = [];
    private $createdExternalCPLMOs = [];
    private $foundExternalCPLMOs = [];
    private $dcExternalCPL = [];
    private $dcExternalCPLLanguageID = [];
    private $dcVendorCompositeCPL = [];
    private $invalidRows = [];
    private $titleABBR;
    private $reelsCount;
    private $ovCplMCAttributes;
    private $vfCplMCAttributes;
    private $territoryList;
    private $titleInfoMO;



    /**
     * GatherOVVFCPLMediaObjects constructor.
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
        return "<h2>Gather DC OV, VF CPL and DC Distribution Package Media Objects</h2>"
            . "<h4>Description:</h4>"
            . "<div>This task creates DC OV, VF CPL and DC Distribution Package Media Objects from Build Plan.</div>"
            . "<h4>Input message parameters:</h4>"
            . "<table>"
            . "<tr> <td>" . self::TITLE_ID . ": </td> <td><input type='text' name='" . self::TITLE_ID . "' size='50' /></td> </tr>"
            . "<tr> <td>" . self::TITLE_VERSION_ID . ": </td> <td><input type='text' name='" . self::TITLE_VERSION_ID . "' size='50' /></td> </tr>"
            . "<tr> <td>" . self::CSV_FILEPATH . ": </td> <td><input type='text' name='" . self::CSV_FILEPATH . "' size='80' /></td> </tr>"
            . "<tr><td>".self::STUDIO_PROFILE." </td><td><select name='".self::STUDIO_PROFILE."'>"
            . "<option value='".ADCP_BUILD_PLAN_STUDIO_PROFILE_DISNEY."' selected='selected'>".ADCP_BUILD_PLAN_STUDIO_PROFILE_DISNEY."</option>"
            . "<option value='".ADCP_BUILD_PLAN_STUDIO_PROFILE_PIXAR."'>".ADCP_BUILD_PLAN_STUDIO_PROFILE_PIXAR."</option>"
            . "</select></td></tr>"
            . "<tr> <td>" . self::DCP_VENDOR . ": </td> <td><input type='text' name='" . self::DCP_VENDOR . "' size='50' /></td> </tr>"
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

        $outputParams = ['created_ov_cpl_media_object_ids' => implode(",", $this->createdMOs),
            'found_ov_cpl_media_object_ids' => implode(",", $this->foundMOs),
            'created_external_cpl_media_object_ids' => implode(",", $this->createdExternalCPLMOs),
            'found_external_cpl_media_object_ids' => implode(",", $this->foundExternalCPLMOs),
            'created_vf_cpl_media_object_ids' => implode(",", $this->createdVFMOs),
            'found_vf_cpl_media_object_ids' => implode(",", $this->foundVFMOs),
            'created_dc_dp_media_object_ids' => implode(",", $this->createdDPMOs),
            'found_dc_dp_media_object_ids' => implode(",", $this->foundDPMOs),
            'created_dc_dp_wailua_media_object_ids' => implode(",", $this->createdDPWMOs),
            'found_dc_dp_wailua_media_object_ids' => implode(",", $this->foundDPWMOs),
        ];

        $addMessage = "";

        if ($this->params[self::DCP_VENDOR] ) {
            $outputParams["created_dc_external_cpl_media_object_ids"] = implode(",", $this->createdExternalCPLMOs);
            $outputParams["found_dc_external_cpl_media_object_ids"] = implode(",", $this->foundExternalCPLMOs);
            $addMessage = count($this->createdExternalCPLMOs). " External CPL MOs created, ".count($this->foundExternalCPLMOs)." External CPL MOs found; ";
        }

        if ($this->params[self::STUDIO_PROFILE] == ADCP_BUILD_PLAN_STUDIO_PROFILE_DISNEY ) {
            $outputParams["created_dc_composite_cpl_media_object_ids"] = implode(",", $this->createdCompositeMOs);
            $outputParams["found_dc_composite_cpl_media_object_ids"] = implode(",", $this->foundCompositeMOs);
            $addMessage .= count($this->createdCompositeMOs). " Composite CPL MOs created, ".count($this->foundCompositeMOs)." Composite CPL MOs found; ";
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
            $this->setTaskSuccess(count($this->createdMOs). " OV CPL MOs created, ".count($this->foundMOs)." OV CPL MOs found; "
                .count($this->createdVFMOs). " VF CPL MOs created, ".count($this->foundVFMOs)." VF CPL MOs found; "
                .$addMessage
                .count($this->createdDPMOs). " DC DP MOs created, ".count($this->foundDPMOs)." DC DP MOs found; "
                .count($this->createdDPWMOs).  " DC DP Wailua MOs created, ".count($this->foundDPWMOs)." DC DP Wailua MOs found");
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

        $this->titleInfoMO = $titleInfoMOs[0];
        $this->reelsCount = $this->titleInfoMO->getAttributeByName("Reels_Count");

        $ovMediaClass = MediaClass::retrieveByID(MEDIA_CLASS_ID_DC_OV_CPL) ;
        $this->ovCplMCAttributes = MediaClass::getAttributesData($ovMediaClass->getAttributes());

        $vfMediaClass = MediaClass::retrieveByID(MEDIA_CLASS_ID_DC_VF_CPL) ;
        $this->vfCplMCAttributes = MediaClass::getAttributesData($vfMediaClass->getAttributes());

        // get a list of possible territories. Should be the same for OV and VF
        foreach($this->ovCplMCAttributes as $attrData) {
            if ($attrData["name"] == "Territory") {
                $this->territoryList = $attrData["options"];
                break;
            }
        }

    }


    /**
     * @throws DataNotFoundException
     * @throws DatabaseErrorException
     * @throws Exception
     * @throws IllegalArgumentException
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
                $moData = [];
                foreach ($data as $key => $value) {
                    if (isset($columns[$key])) {
                        $moData[$columns[$key]] = $value;
                    }
                }
                if (preg_match("/_OV$/i", $moData["name"])) {
                    $moData["type"] = "OV";
                    if ($this->params[self::DCP_VENDOR]) {
                        if (preg_match("/_{$this->params[self::DCP_VENDOR]}_/i", $moData["name"])) {
                            $moData["type"] = "External";
                        }
                    }
                } else {
                    $moData["type"] = "VF";
                    if (preg_match("/N\/A, PARENT\/OV/i", $moData["parent_ref"])) {
                        $this->invalidRows[] = "Row {$rowN}: parent reference is not set";
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

                $moData["possible_inputs"] = $this->getPossibleInputs($moData);

                $mo_key = $moData["name"]."_".$moData["parent_ref"];
                $moData["csvRow"] = $rowN;

                $mediaObjectsData[$mo_key] = $moData;
            }
            $rowN++;
        }

        if (empty($this->invalidRows)) {

            $this->titleABBR =  $this->titleInfoMO->getAttributeByName("th_title_abbr");

            foreach ($mediaObjectsData as &$moData) {
                // OV CPL and External CVP first
                if ($moData["type"] == "OV") {
                    $this->createOVMediaObject($moData);
                }
                if ($moData["type"] == "External") {
                    $this->createExternalCPLMediaObject($moData);
                }
            }
            $this->foundMOs = array_diff($this->foundMOs, $this->createdMOs);

        }

        if (empty($this->invalidRows)) {
            foreach ($mediaObjectsData as &$moData) {
                if ($moData["type"] == "VF") {
                    $this->createVFMediaObject($moData);
                }
            }
            $this->foundVFMOs = array_diff($this->foundVFMOs, $this->createdVFMOs);
        }


        if ($this->params[self::STUDIO_PROFILE] == ADCP_BUILD_PLAN_STUDIO_PROFILE_DISNEY ) {
            if (empty($this->invalidRows)) {
                foreach ($mediaObjectsData as $moData1) {
                    // The VF where Dubcard is called for in the picture column will also generate a composite CPL media class.
                    if (preg_match("/Dub Cards/i", $moData1["picture"]) || preg_match("/Wtlr/i", $moData1["name"])) {
                        $this->createCompositeCPLMediaObjects($moData1);
                    }
                }
                $this->foundCompositeMOs = array_diff($this->foundCompositeMOs, $this->createdCompositeMOs);
            }
        }

        if (empty($this->invalidRows)) {
            // created VF CPL with DC External CPL as PrimaryCPL should be a parent of DC Composite CPL
            foreach ($this->dcVendorCompositeCPL as $data) {
                $this->createVendorCompositeCPLMediaObjects($data);
            }

            $this->foundCompositeMOs = array_diff($this->foundCompositeMOs, $this->createdCompositeMOs);
        }



        if (empty($this->invalidRows)) {
            foreach ($this->dcPackages as $moData) {
                $this->createDCDPMediaObject($moData);
            }
            $this->foundDPMOs = array_diff($this->foundDPMOs, $this->createdDPMOs);
            $this->foundDPWMOs = array_diff($this->foundDPWMOs, $this->createdDPWMOs);
        }

    }


    /**
     * @param $moData
     * @return array
     * @throws DatabaseErrorException
     */
    private function getPossibleInputs($moData) {
        $inputs = [];

        $audio = $this->getDCAudio($moData);
        if ($audio) {
            $inputs["audio"] = $audio;
        }
        if($moData['name'] && strpos(strtoupper($moData['name']), 'ATMOS') !== false){
            $audioATMOS = $this->getDCAudio($moData, true);
            if ($audioATMOS) {
                $inputs["audioATMOS"] = $audioATMOS;
            }
        }
        if ($moData["type"] == "OV" || $moData["type"] == "External") {
            $picture = $this->getDCPicture($moData);
            if ($picture) {
                $inputs["picture"] = $picture;
            }
        }
        if ( preg_match('/Inserts/i',$moData['picture']) || ($moData["language_ntt"] && $moData["dim"] == "3D")) {
            $pictureLIR = $this->getDCPictureLIR($moData);
            if ($pictureLIR) {
                $inputs["pictureLIR"] = $pictureLIR;
            }
        }
        $subtitle = $this->getDCSubtitle($moData);
        if ($subtitle) {
            $inputs["subtitle"] = $subtitle;
        }
        $compositeAudio = $this->getDCCompositeAudio($moData);
        if ($compositeAudio) {
            $inputs["comositeAudio"] = $compositeAudio;
        }

        if ($this->params[self::STUDIO_PROFILE] == ADCP_BUILD_PLAN_STUDIO_PROFILE_PIXAR ) {
            $pictureReel = $this->getDCPictureReel($moData);
            if ($pictureReel) {
                $inputs["pictureReel"] = $pictureReel;
            }
        }

        if ($this->params[self::STUDIO_PROFILE] == ADCP_BUILD_PLAN_STUDIO_PROFILE_DISNEY ) {
            $externalCPL = $this->getDCExternalCPL($moData);
            if ($externalCPL) {
                $inputs["externalCPL"] = $externalCPL;
            }
        }
        if (preg_match("/Wtlr/i", $moData["name"])) {
            $trailerExternalCPL = $this->getTrailerDCExternalCPL($moData);
            if ($trailerExternalCPL) {
                $inputs["trailerExternalCPL"] = $trailerExternalCPL;
            }
        }
        return $inputs;
    }

    /**
     * @param $moData
     * @param $isATMOS
     * @return array|null
     * @throws DatabaseErrorException
     */
    private function getDCAudio($moData, $isATMOS = false) {

        $audioConfig = $this->getAudioConfig($moData["audio_format"]);
        if (!$audioConfig) {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown audio format '{$moData["audio_format"]}'";
            return null;
        }

        if($audioConfig == "51" || $audioConfig == "71"){
            $format = "WAV";
        }

        if($isATMOS){
            $audioConfig = "ATMOS";
            $format = "MXF";
        }

        $subclassAttrs = [$audioConfig, $format];
        sort($subclassAttrs);
        $subclass = implode("-", $subclassAttrs);

        $moID = null;
        $mo = MediaObject::getMODataByClassVersionLngSubclass(MEDIA_CLASS_ID_DC_AUDIO, $this->params[self::TITLE_VERSION_ID], $moData["languageID"], $subclass);
        if ($mo) {
           // $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find DC Audio $audioConfig '{$moData["language"]}'";
            $moID = $mo[MediaObject::ID];
        }

        return ["mo_id" => $moID, "language_id" => $moData["languageID"], "audio_config" => $audioConfig, "subclass" => $audioConfig];
    }

    /**
     * @param $moData
     * @return array|null
     * @throws DatabaseErrorException
     */
    private function getDCPicture($moData) {
        if (preg_match("/^Domestic/i", $moData["picture"]) || preg_match("/^Localized/i", $moData["picture"])) {
            $languageID = $moData["languageID"];
            //$lngName = $moData["language"];
        } else {
            $languageID  = LANGUAGE_ALL_ID;
           // $lngName = "ALL";
        }
        $isDCDM = false;
        if($this->params[self::SOURCE_TYPE] == 'DCDM'){
            $isDCDM = true;
        }
        $standard = $this->getDCFormat($moData["standard"],$isDCDM);

        if (!$standard) {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown standard '{$moData["standard"]}'";
            return null;
        }

        // concatenated "unique" attributes' values in alphabetical order with "-" separator.
        $subclassAttrs = [$standard, $moData["dim"]];
        sort($subclassAttrs);
        $subclass = implode("-", $subclassAttrs);

        $moID = null;
        $mo = MediaObject::getMODataByClassVersionLngSubclass(MEDIA_CLASS_ID_DC_PICTURE, $this->params[self::TITLE_VERSION_ID], $languageID, $subclass);
        if ($mo) {
           // $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find DC Picture $subclass $lngName";
            $moID = $mo[MediaObject::ID];
        }

        return ["mo_id" => $moID, "language_id" => $languageID, "DC_Format" => $standard, "Dimension" => $moData["dim"], "subclass" => $subclass];
    }

    /**
     * @param $moData
     * @return array|null
     * @throws DatabaseErrorException
     */
    private function getDCPictureLIR($moData) {

        $languageID = $moData["languageID"];
        if (!$languageID) {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown language '{$moData["language"]}'";
        }
        $isDCDM = false;
        if($this->params[self::SOURCE_TYPE] == 'DCDM'){
            $isDCDM = true;
        }
        $standard = $this->getDCFormat($moData["standard"],$isDCDM);
        if (!$standard) {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown standard '{$moData["standard"]}'";
            return null;
        }

        // concatenated "unique" attributes' values in alphabetical order with "-" separator.
        $subclassAttrs = [$standard, $moData["dim"]];
        sort($subclassAttrs);
        $subclass = implode("-", $subclassAttrs);

        $moID = null;
        $mo = MediaObject::getMODataByClassVersionLngSubclass(MEDIA_CLASS_ID_DC_PICTURE_LIR, $this->params[self::TITLE_VERSION_ID], $languageID, $subclass);
        if ($mo) {
            $moID = $mo[MediaObject::ID];
        }

        return ["mo_id" => $moID, "language_id" => $languageID, "DC_Format" => $standard, "Dimension" => $moData["dim"], "subclass" => $subclass];
    }

    /**
     * @param $moData
     * @return array|null
     * @throws DatabaseErrorException
     */
    private function getDCSubtitle($moData) {
        if (!($moData["language_ntt"] || $moData["language_sub1"] || $moData["language_sub2"]) || ($moData["language_ntt"] && $moData["dim"] == "3D")) {
            return null;
        }

        $languageID = null;

        if ($moData["language_ntt"]) {
            $languageID = LanguageTable::getLanguageByDCPSubtitleName($moData["language_ntt"]);
            if (!$languageID) {
                $languageID = LanguageTable::getLanguageByName($moData["language_ntt"]);
            }
            if (!$languageID) {
                $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown language '".$moData["language_ntt"]."'";
                return null;
            }
        } else {
            $languageID = LanguageTable::getLanguageByDCPSubtitleName($moData["language_sub1"].$moData["language_sub2"]);
            if (!$languageID) {
                $languageID = LanguageTable::getLanguageByName($moData["language_sub1"].$moData["language_sub2"]);
            }
            if (!$languageID) {
                $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown language '".$moData["language_sub1"].$moData["language_sub2"]."'";
                return null;
            }
        }

        $standard = $this->getDCFormat($moData["standard"]);
        if (!$standard) {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown standard '{$moData["standard"]}'";
            return null;
        }

        $type = $this->getSubtitleType($moData);
        if (!$type) {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to determine subtitle type'";
            return null;
        }

        $dim = $moData["dim"];
        if(!$dim){
            $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to determine subtitle Dim '";
        }
        if($type == "NTT" && $dim == "3D"){
            return null;
        }
        $subclassAttrs = [$standard, $type, $dim];
        sort($subclassAttrs);
        $subclass = implode("-", $subclassAttrs);

        $moID = null;
        $mo = MediaObject::getMODataByClassVersionLngSubclass(MEDIA_CLASS_ID_DC_SUBTITLE, $this->params[self::TITLE_VERSION_ID], $languageID, $subclass);
        if ($mo) {
            $moID = $mo[MediaObject::ID];
        }

        return ["mo_id" => $moID, "language_id" => $languageID, "DC_Format" => $standard, "Type" => $type, "subclass" => $subclass, "Dimension" => $dim];
    }

    /**
     * @param $moData
     * @return array|null
     * @throws DatabaseErrorException
     */
    private function getDCCompositeAudio($moData) {
        if (preg_match("/(51|71)-([A-Za-z0-9\-]+)/", preg_replace('/-Atmos/i', '', $moData['name']), $matches)) {
            $audioConfig = $matches[0];
            $audioConfig = strtoupper($audioConfig);
            $audioConfigs = explode("-", $audioConfig);
            sort($audioConfigs);
            $audioConfigs[0] .= "-WAV";
        }elseif(preg_match("/(51|71)+/", $moData['name'], $matches)){
            $audioConfigs[0] = $matches[0] . "-WAV";
        }else {
            // it's not a composite audio
            return null;
        }

        if(preg_match("/IOP/i", $moData["name"])){
            array_push($audioConfigs,"IOP");
        }elseif(preg_match("/Atmos/i", $moData["name"]) && preg_match("/SMPTE/i", $moData["name"])){
            array_push($audioConfigs,"SMPTE20");
        }elseif(preg_match("/SMPTE/i", $moData["name"])){
            array_push($audioConfigs,"SMPTE21");
        }
        // concatenated parents' subclass values in alphabetical order with "_" separator.
        $subclass = implode ("_", $audioConfigs);

        $moID = null;
        $mo = MediaObject::getMODataByClassVersionLngSubclass(MEDIA_CLASS_ID_DC_COMPOSITE_AUDIO, $this->params[self::TITLE_VERSION_ID], $moData["languageID"], $subclass);
        if ($mo) {
            $moID = $mo[MediaObject::ID];
        }

        return ["mo_id" => $moID, "language_id" => $moData["languageID"], "subclass" => $subclass];

    }

    /**
     * @param $moData
     * @return array|null
     * @throws DatabaseErrorException
     */
    private function getDCPictureReel($moData) {
        if (!($moData["picture"] && $moData["dim"] && $moData["standard"])) {
            return null;
        }

        $standard = $this->getDCFormat($moData["standard"]);
        if (!$standard) {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown standard '{$moData["standard"]}'";
            return null;
        }

        $subclassAttrs = [$standard, $moData["dim"], $this->reelsCount];
        sort($subclassAttrs);
        $subclass = implode ("-", $subclassAttrs);

        $moID = null;
        $mo = MediaObject::getMODataByClassVersionLngSubclass(MEDIA_CLASS_ID_DC_PICTURE_REEL, $this->params[self::TITLE_VERSION_ID], $moData["languageID"], $subclass);
        if ($mo) {
            $moID = $mo[MediaObject::ID];
        }

        return ["mo_id" => $moID, "language_id" => $moData["languageID"], "picture" => $moData["picture"], "subclass" => $subclass];

    }

    /**
     * @param $moData
     * @return array|null
     * @throws DatabaseErrorException
     */
    private function getDCExternalCPL($moData) {
        if (!($moData["picture"] && $moData["languageID"] && $moData["standard"] && $moData["dim"])) {
            return null;
        }

        if (!preg_match("/Dub Cards/i", $moData["picture"])) {
           return null;
        }

        $standard = $this->getDCFormat($moData["standard"]);
        if (!$standard) {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown standard '{$moData["standard"]}'";
            return null;
        }

        $audio_language = $this->getAudioLanguage($moData["name"]);

        $subclassAttrs = [$standard, $moData["dim"],"dubcard", $audio_language];
        sort($subclassAttrs);
        $subclass = implode ("-", $subclassAttrs);

        $moID = null;
        $mo = MediaObject::getMODataByClassVersionLngSubclass(MEDIA_CLASS_ID_DC_EXTERNAL_OV_CPL, $this->params[self::TITLE_VERSION_ID], $moData["languageID"], $subclass);
        if ($mo) {
            $moID = $mo[MediaObject::ID];
        }

        return ["mo_id" => $moID, "language_id" => $moData["languageID"],  "subclass" => $subclass];

    }

    /**
     * @param $moData
     * @return array|null
     * @throws DatabaseErrorException
     */
    private function getTrailerDCExternalCPL($moData) {
        if (!($moData["picture"] && $moData["languageID"] && $moData["standard"] && $moData["dim"])) {
            return null;
        }

        $standard = $this->getDCFormat($moData["standard"]);
        if (!$standard) {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: unknown standard '{$moData["standard"]}'";
            return null;
        }

        $audio_language = $this->getAudioLanguage($moData["name"]);

        $subclassAttrs = [$standard, $moData["dim"],"trailer", $audio_language];
        sort($subclassAttrs);
        $subclass = implode ("-", $subclassAttrs);

        $moID = null;
        $mo = MediaObject::getMODataByClassVersionLngSubclass(MEDIA_CLASS_ID_DC_EXTERNAL_OV_CPL, $this->params[self::TITLE_VERSION_ID], $moData["languageID"], $subclass);
        if ($mo) {
            $moID = $mo[MediaObject::ID];
        }

        return ["mo_id" => $moID, "language_id" => $moData["languageID"],  "subclass" => $subclass];

    }

    /**
     * @param $moData
     * @throws DatabaseErrorException
     * @throws Exception
     */
    private function createOVMediaObject(&$moData) {

        if (!isset($moData["possible_inputs"]["picture"]) || !isset($moData["possible_inputs"]["audio"])) {
            // TODO  skip for now
            $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to determine Picture, Audio  parents";
            return;
        }

        $parentsData = $this->getOVCPLParents($moData);
        if (!$parentsData) {
            return;
        }


        $name = $moData["name"];
        $moName = $this->getMOName($name);

        if ($moData["territory"] == "Original Version") {
            $languageID = LANGUAGE_ALL_ID;
        }

        $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_OV_CPL);
        $mediaObject->setName($moName);
        $mediaObject->setTitleID($this->params[self::TITLE_ID]);
        $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
        $mediaObject->setLanguageID($languageID);

        $mediaObject->setParents($parentsData);

        $pictureDim = $moData["possible_inputs"]["picture"]["Dimension"];
        $pictureLIRDim = $moData["possible_inputs"]["pictureLIR"]["Dimension"];
        $subtitleDim = $moData["possible_inputs"]["subtitle"]["Dimension"];
        $dim = '';
        if($pictureDim == "3D" || $pictureLIRDim == "3D" || $subtitleDim == "3D"){
            $dim = "3D";
        }
        $haveSub = isset($moData["possible_inputs"]["subtitle"]);
        $this->setMOAttributes($mediaObject, $this->ovCplMCAttributes, $dim, $haveSub);
        $mediaObject->calculateSubclass();
        $mediaObject->setCreatedDate(Database::asSQLDateTime(time()));
        $result = MOUtils::checkMediaObjectUniqueness(0, $mediaObject->getMediaClassID(), $mediaObject->getTitleID(), $mediaObject->getTitleVersionID(), $mediaObject->getLanguageID(), $mediaObject->getSubclass(), null);
        if ($result === true) {
            $mediaObject->insert();
            $ovMOID = $mediaObject->getID();
            MediaObjectInputs::updateMediaObjectParents($mediaObject->getID(), $parentsData);
            $this->createdMOs[$mediaObject->getID()] = $mediaObject->getID();
            $this->dcOVCPL[$moData["name"]] = $mediaObject->getID();
            $this->dcOVCPLLanguageID[$mediaObject->getID()] = $languageID;
        } else {
            $this->foundMOs[$result] = $result;
            $this->dcOVCPL[$moData["name"]] = $result;
            $this->dcOVCPLLanguageID[$mediaObject->getID()] = $languageID;
            $ovMOID = $result;
        }
        $moData["possible_inputs"]["ov_cpl"] = $ovMOID;
        $this->dcPackages[$ovMOID] = ["input_id" => $ovMOID, "name" => $moName, "languageID" => $languageID];

    }


    /**
     * @param $moData
     * @return array|bool
     */
    private function getOVCPLParents($moData) {

        $parentsData = [];


        $moID = $moData["possible_inputs"]["picture"]["mo_id"];
        if (!$moID) {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find DC Picture ".$moData["possible_inputs"]["picture"]["subclass"]. " LngID ". $moData["possible_inputs"]["picture"]["language_id"];
            return false;
        }

        $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $moID,
            MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
            MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_PICTURE
        ];


        if (isset($moData["possible_inputs"]["pictureLIR"])) {
            $moID = $moData["possible_inputs"]["pictureLIR"]["mo_id"];
            if (!$moID) {
                $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find DC Picture LIR " . $moData["possible_inputs"]["pictureLIR"]["subclass"] . " LngID " . $moData["possible_inputs"]["pictureLIR"]["language_id"];
                return false;
            }
            $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT => $moID,
                MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
                MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_INSERT
            ];
        }

        $audio = $moData["possible_inputs"]["audio"];
        $audioClass = "DC Audio";
        $languageID = $audio["language_id"];
        if (isset($moData["possible_inputs"]["comositeAudio"])) {
            $audio = $moData["possible_inputs"]["comositeAudio"];
            $audioClass = "DC Composite Audio";
            $languageID = $audio["language_id"];
        }

        $moID = $audio["mo_id"];
        if (!$moID) {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find $audioClass ".$audio["subclass"]. " LngID $languageID";
            return false;
        }
        $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $moID,
            MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
            MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_AUDIO
        ];
        if (isset($moData["possible_inputs"]["audioATMOS"])) {
            $audioATMOS = $moData["possible_inputs"]["audioATMOS"];
            $languageID = $audioATMOS["language_id"];
            $moID = $audioATMOS["mo_id"];
            if (!$moID) {
                $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find audioATMOS ".$audioATMOS["subclass"]. " LngID $languageID";
                return false;
            }
            $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $moID,
                MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
                MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_ATMOS
            ];
        }

        if (isset($moData["possible_inputs"]["subtitle"])) {
            $subtitle = $moData["possible_inputs"]["subtitle"];
            $languageID = $subtitle["language_id"];
            $moID = $subtitle["mo_id"];
            if (!$moID) {
                $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find DC Subtitle ".$subtitle["subclass"]. " LngID $languageID";
                return false;
            }
            $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $moID,
                MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
                MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_TIME_TEXT
            ];
        }

        return $parentsData;
    }
    /**
     * @param $name
     * @return string
     */
    private function getMOName($name) {
        if (preg_match("/^(.+)_(FTR|TRL|TSR|SHR|PSA|RTG|POL|ADV|XSN|TST)-(.+)/", $name , $matches)) {
            $s = $matches[1];
            $name = str_replace($s, $this->titleABBR, $name);
        }
        $name = preg_replace('/_\d+MMDD_/', '_YYYYMMDD_', $name);
        return $name;
    }

    /**
     * @param $moData
     * @throws DatabaseErrorException
     */
    private function createExternalCPLMediaObject(&$moData) {

        //  DC External CPL will be substituting the DC OV CPL that is very simple like only have a few inputs.
        //  So the task should just parse the name of DC External CPL and figure out what inputs it would have if it would have been DC OV CPL
        $parentsData = $this->getOVCPLParents($moData);
        if (!$parentsData) {
            return;
        }
        $ovcplParents = [];
        foreach($parentsData as $p) {
            $ovcplParents[] = [MediaObject::ID => $p[MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT]];
        }

        $languageID = $moData["languageID"];
        if ($moData["territory"] == "Original Version") {
            $languageID = LANGUAGE_ALL_ID;
        }

        $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_EXTERNAL_OV_CPL);
        $mediaObject->setName($this->getMOName($moData["name"]));
        $mediaObject->setTitleID($this->params[self::TITLE_ID]);
        $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
        $mediaObject->setLanguageID($languageID);


        $mediaObject->setAttributesByName($this->getExternalCPLMOAttributes($moData));
        $mediaObject->calculateSubclass();
        $mediaObject->setCreatedDate(Database::asSQLDateTime(time()));
        $result = MOUtils::checkMediaObjectUniqueness(0, $mediaObject->getMediaClassID(), $mediaObject->getTitleID(), $mediaObject->getTitleVersionID(), $mediaObject->getLanguageID(), $mediaObject->getSubclass(), null);
        if ($result === true) {
            $mediaObject->insert();
            $MOID = $mediaObject->getID();
            $this->createdExternalCPLMOs[$mediaObject->getID()] = $mediaObject->getID();
            $this->dcExternalCPL[$moData["name"]]["moid"] = $mediaObject->getID();
            $this->dcExternalCPLLanguageID[$mediaObject->getID()] = $languageID;
        } else {
            $this->foundExternalCPLMOs[$result] = $result;
            $this->dcExternalCPL[$moData["name"]]["moid"] = $result;
            $this->dcExternalCPLLanguageID[$mediaObject->getID()] = $languageID;

            $MOID = $result;
        }
        $this->dcExternalCPL[$moData["name"]]["ovcplParents"] = $ovcplParents;
        $moData["possible_inputs"]["external_cpl"] = $MOID;

    }

    /**
     * @param $moData
     * @return array
     */
    private function getExternalCPLMOAttributes ($moData) {
        $attrs = [];

        $name = $moData["name"];

        $audioConfig[] = $this->getAudioConfig($moData["audio_format"]);

        $addAudioType = ["HI", "VI", "Dbox", "Atmos"];
        foreach ($addAudioType as $at) {
            if (preg_match("/-($at)(-|_)/i", $name, $matches)) {
                $audioConfig[] = strtoupper($matches[1]);
            }
        }
        sort($audioConfig);
        $attrs["Audio_Config"] = implode("_", $audioConfig);
        $attrs["Dimension"] = $moData["dim"];
        $attrs["Movie_Title"] = $this->titleABBR;
        $audio_lng = $subtitle_lng =  "";
        if (preg_match("/_([A-Za-z\-]+)_([A-Za-z\-]+)_(51|71)/i", $name, $matches) ) {
            $s = $matches[1];
            $a = explode("-", $s, 2);
            $audio_lng = $a[0];
            $subtitle_lng = $a[1];
        }
        $attrs["Audio_Language"] = $audio_lng;
        $attrs["Subtitle_Language"] = $subtitle_lng;

        $terr = $this->getTerritory($name);
        if ($terr) {
            $attrs["Territory"] = $terr;
        }

        $attrs["Resolution"] = $this->getMOResolution($name);
        $attrs["Aspect_Ratio"] = $this->getAspectRatio($name);
        $attrs["Content_Kind"] = $this->getContentKind($name);

        if (preg_match("/_IOP/i", $name, $matches)) {
            $attrs["DC_Format"] = "IOP";
        } elseif (preg_match("/_SMPTE/i", $name, $matches)) {
            if (preg_match("/-Atmos(-|_)/i", $name, $matches)) {
                $attrs["DC_Format"] = "SMPTE20";
            } else {
                $attrs["DC_Format"] = "SMPTE21";
            }
        }

        return $attrs;

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
            $terr = "INT";
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
     * @param $name
     * @return null
     */
    private function getAspectRatio($name) {
        $result = null;

        if (preg_match("/([A-Za-z0-9\-]+)_([A-Za-z0-9\-]+)_([A-Za-z0-9\-]+)_(51|71)([A-Za-z0-9\-]*)/", $name, $matches)) {
            $r = $matches[1];
            $a = explode("-", $r, 2);
            $result = $a[0];
        }
        return $result;

    }

    /**
     * @param $name
     * @return null
     */
    private function getContentKind($name) {

        if (preg_match("/([A-Za-z0-9]+)_([A-Z]+)-/", $name, $matches)) {
            $result = $matches[2];
        }

        switch ($result) {
            case "FTR":
                return "feature";
            case "TRL":
                return "trailer";
            case "TSR":
                return "teaser";
            case "SHR":
                return "short";
            case "PSA":
                return "psa";
            case "RTG":
                return "rating";
            case "POL":
                return "policy";
            case "ADV":
                return "advertisement";
            case "XSN":
                return "transitional";
            case "TST":
                return "test";
            default:
                return null;

        }


    }


    /**
     * @param $moData
     * @throws DataNotFoundException
     * @throws DatabaseErrorException
     * @throws Exception
     * @throws IllegalArgumentException
     */
    private function createVFMediaObject(&$moData) {

        $baseCPLID = null;
        $baseExternalCPL = false;

        if (isset($this->dcOVCPL[$moData["parent_ref"]])) {
            $baseCPLID = $this->dcOVCPL[$moData["parent_ref"]];
            $languageID = $this->dcOVCPLLanguageID[$baseCPLID];
            $baseCPLInputs = MediaObjectInputs::getMOParentsData($baseCPLID);
        } elseif (isset($this->dcExternalCPL[$moData["parent_ref"]])) {
            // External CPL for Main Feature
            $baseCPLID = $this->dcExternalCPL[$moData["parent_ref"]]["moid"];
            $languageID = $this->dcExternalCPLLanguageID[$baseCPLID];
            // DC External CPL will be substituting the DC OV CPL
            // ["ovcplParents"] contains inputs it would have if it would have been DC OV CPL
            $baseCPLInputs = $this->dcExternalCPL[$moData["parent_ref"]]["ovcplParents"];
            $baseExternalCPL = true;
        }

        if (!$baseCPLID) {
            $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find Parent CPL ".$moData["parent_ref"];
            return;
        }


        $parentsData = [];

        $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $baseCPLID,
            MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
            MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_BASE_CPL
        ];

        $haveSub = false;


        // add additional parents


        if (isset( $moData["possible_inputs"]["audio"])) {
            $audio = $moData["possible_inputs"]["audio"];
            $audioClass = "DC Audio";
            if (isset($moData["possible_inputs"]["comositeAudio"])) {
                $audio = $moData["possible_inputs"]["comositeAudio"];
                $audioClass = "DC Composite Audio";
            }
            $moID = $audio["mo_id"];
            if (!$moID) {
                $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find $audioClass ".$audio["subclass"]. " LngID ".$audio["language_id"];
                return;
            }

            if (!$this->isOVCPLInput($moID, $baseCPLInputs)) {
                $languageID = $audio["language_id"];
                $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $moID,
                    MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
                    MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_AUDIO
                ];
            }
        }

        if (isset($moData["possible_inputs"]["audioATMOS"])) {
            $audioATMOS = $moData["possible_inputs"]["audioATMOS"];
            $languageID = $audioATMOS["language_id"];
            $moID = $audioATMOS["mo_id"];
            if (!$moID) {
                $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find audioATMOS ".$audioATMOS["subclass"]. " LngID $languageID";
                return;
            }

            if (!$this->isOVCPLInput($moID, $baseCPLInputs)) {
                $languageID = $audioATMOS["language_id"];
                $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT => $moID,
                    MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
                    MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_ATMOS
                ];
            }
        }

        $subtitleDim = null;

        if (isset($moData["possible_inputs"]["subtitle"])) {
            $haveSub = true;
            $subtitle = $moData["possible_inputs"]["subtitle"];
            $moID = $subtitle["mo_id"];
            if (!$moID) {
                $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find DC Subtitle ".$subtitle["subclass"]. " LngID ".$subtitle["language_id"];
                return;
            }
            $subtitleDim = $moData["possible_inputs"]["subtitle"]["Dimension"];
            if (!$this->isOVCPLInput($moID, $baseCPLInputs)) {
                $languageID = $subtitle["language_id"];
                $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $moID,
                    MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
                    MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_TIME_TEXT
                ];
            }
        }

        $pictureLIRDim = null;
        if (isset($moData["possible_inputs"]["pictureLIR"])) {
            $pictureLIR = $moData["possible_inputs"]["pictureLIR"];
            $moID = $pictureLIR["mo_id"];
            if (!$moID) {
                $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find DC Picture LIR " . $moData["possible_inputs"]["pictureLIR"]["subclass"] . " LngID " . $moData["possible_inputs"]["pictureLIR"]["language_id"];
                return;
            }
            $pictureLIRDim = $moData["possible_inputs"]["pictureLIR"]["Dimension"];
            if (!$this->isOVCPLInput($moID, $baseCPLInputs)) {
                $languageID = $pictureLIR["language_id"];
                $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT => $moID,
                    MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
                    MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_INSERT
                ];
            }


        }

        //  VF CPL for Disney shouldn't have Picture Reel input
        if ($this->params[self::STUDIO_PROFILE] == ADCP_BUILD_PLAN_STUDIO_PROFILE_PIXAR ) {
            if (isset($moData["possible_inputs"]["pictureReel"]) && $moData["possible_inputs"]["pictureReel"]["picture"] == "INTL-OV/Neutral with Localized Final Reel / Dub Cards") {
                $pictureReel = $moData["possible_inputs"]["pictureReel"];
                $moID = $pictureReel["mo_id"];
                if (!$moID) {
                    $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find DC Picture Reel ".$pictureReel["subclass"]. " LngID ".$pictureReel["language_id"];
                    return;
                }

                if (!$this->isOVCPLInput($moID, $baseCPLInputs)) {
                    $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $moID,
                        MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
                        MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_PICTURE_REEL
                    ];
                }
            }
        }


        $name = $moData["name"];
        $moName = $this->getMOName($name);

        $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_VF_CPL);
        $mediaObject->setName($moName);
        $mediaObject->setTitleID($this->params[self::TITLE_ID]);
        $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
        $mediaObject->setLanguageID($languageID);

        $mediaObject->setParents($parentsData);

        $pictureDim = null;
        if(isset($moData["possible_inputs"]["picture"]["Dimension"])){
            $pictureDim = $moData["possible_inputs"]["picture"]["Dimension"];
        }
        $dim = '';
        if($pictureDim == "3D" || $pictureLIRDim == "3D" || $subtitleDim == "3D"){
            $dim = "3D";
        }
        $this->setMOAttributes($mediaObject, $this->vfCplMCAttributes, $dim, $haveSub);
        $mediaObject->calculateSubclass();
        $result = MOUtils::checkMediaObjectUniqueness(0, $mediaObject->getMediaClassID(), $mediaObject->getTitleID(), $mediaObject->getTitleVersionID(), $mediaObject->getLanguageID(), $mediaObject->getSubclass(), null);
        if ($result === true) {
            $mediaObject->setCreatedDate(Database::asSQLDateTime(time()));
            $mediaObject->insert();
            $vfMOID = $mediaObject->getID();
            MediaObjectInputs::updateMediaObjectParents($mediaObject->getID(), $parentsData);
            $this->createdVFMOs[$mediaObject->getID()] = $mediaObject->getID();
        } else {
            $this->foundVFMOs[$result] = $result;
            $vfMOID = $result;
        }

        $moData["possible_inputs"]["vf_cpl"] = $vfMOID;

        $this->dcPackages[$vfMOID] = ["input_id" => $vfMOID, "name" => $moName, "languageID" => $languageID];

        if ($baseExternalCPL) {
            $this->dcVendorCompositeCPL[$vfMOID] = ["input_id" => $vfMOID, "name" => $moName, "languageID" => $languageID];
        }

    }

    /**
     * @param $moData
     * @throws DataNotFoundException
     * @throws DatabaseErrorException
     * @throws Exception
     * @throws IllegalArgumentException
     */
    private function createVendorCompositeCPLMediaObjects($moData) {

        $MO = MediaObject::retrieveByID($moData["input_id"]);

        $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_COMPOSITE_CPL);
        $mediaObject->setName($MO->getName()."-compCPL");
        $mediaObject->setTitleID($this->params[self::TITLE_ID]);
        $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
        $mediaObject->setLanguageID($MO->getLanguageID());

        $attrs = ["Base_Name" => $MO->getName(),
            "DC_Format" => $MO->getAttributeByName("DC_Format"),
            "Territory" => $MO->getAttributeByName("Territory"),
        ];
        $mediaObject->setAttributesByName($attrs);

        $parentsData = [];

        $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $MO->getID(),
            MEDIA_OBJECT_INPUTS_ORDINAL_NO => 1,
            MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_PRIMARY_CPL
        ];


        $mediaObject->setParents($parentsData);

        $mediaObject->calculateSubclass();
        $result = MOUtils::checkMediaObjectUniqueness(0, $mediaObject->getMediaClassID(), $mediaObject->getTitleID(), $mediaObject->getTitleVersionID(), $mediaObject->getLanguageID(), $mediaObject->getSubclass(), null);
        if ($result === true) {
            $mediaObject->setCreatedDate(Database::asSQLDateTime(time()));
            $mediaObject->insert();
            $compositeMOID = $mediaObject->getID();
            MediaObjectInputs::updateMediaObjectParents($mediaObject->getID(), $parentsData);
            $this->createdCompositeMOs[$mediaObject->getID()] = $mediaObject->getID();
        } else {
            $this->foundCompositeMOs[$result] = $result;
            $compositeMOID = $result;
        }

        // if there is a composite CPL created then the DP will reference  the composite CPL, otherwise the  VF CPL
        $this->dcPackages[$MO->getID()]["input_id"] = $compositeMOID;

    }


    /**
     * @param $moData
     * @throws DataNotFoundException
     * @throws DatabaseErrorException
     * @throws Exception
     * @throws IllegalArgumentException
     */
    private function createCompositeCPLMediaObjects($moData) {

        if (!isset($moData["possible_inputs"]["externalCPL"]) && !isset($moData["possible_inputs"]["trailerExternalCPL"])) {
            // TODO  skip for now
            return;
        }

        if(isset($moData["possible_inputs"]["externalCPL"])){
            $externalMOData = $moData["possible_inputs"]["externalCPL"];
            $externalMOID = $externalMOData["mo_id"];

            if (!$externalMOID) {
                $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find DC External CPL ".$externalMOData["subclass"]. " LngID ".$externalMOData["language_id"]. " Dim ".$externalMOData["dim"];
                return;
            }
        }

        if(isset($moData["possible_inputs"]["vf_cpl"]) && $moData["type"] == "VF"){
            $MO = MediaObject::retrieveByID($moData["possible_inputs"]["vf_cpl"]);
        }else if(isset($moData["possible_inputs"]["ov_cpl"]) && $moData["type"] == "OV"){
            $MO = MediaObject::retrieveByID($moData["possible_inputs"]["ov_cpl"]);
        }else{
            $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find OV/VF CPL Type is". $moData["type"] . "possible input is" . json_encode($moData["possible_inputs"]);
            return;
        }

        if(empty($MO)){
            $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find OV/VF CPL ";
            return;
        }

        $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_COMPOSITE_CPL);
        $mediaObject->setName($MO->getName()."-compCPL");
        $mediaObject->setTitleID($this->params[self::TITLE_ID]);
        $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
        $mediaObject->setLanguageID($MO->getLanguageID());

        $attrs = ["Base_Name" => $MO->getName(),
            "Dimension" => $MO->getAttributeByName("Dimension"),
            "DC_Format" => $MO->getAttributeByName("DC_Format"),
            "Territory" => $MO->getAttributeByName("Territory"),
        ];
        $mediaObject->setAttributesByName($attrs);

        $parentsData = [];

        $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $MO->getID(),
            MEDIA_OBJECT_INPUTS_ORDINAL_NO => 1,
            MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_PRIMARY_CPL
        ];

        if(!empty($externalMOID)){
            $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $externalMOID,
                MEDIA_OBJECT_INPUTS_ORDINAL_NO => 2,
                MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_PARENT
            ];
        }

        if(isset($moData["possible_inputs"]["trailerExternalCPL"])){
            $trailerExternalMOData = $moData["possible_inputs"]["trailerExternalCPL"];
            $trailerExternalMOID = $trailerExternalMOData["mo_id"];
            if (!$trailerExternalMOID) {
                $this->invalidRows[] = "Row {$moData["csvRow"]}: failed to find Trailer DC External CPL ".$trailerExternalMOData["subclass"]. " LngID ".$trailerExternalMOData["language_id"]. " Dim ".$trailerExternalMOData["dim"];
                return;
            }
            $parentsData[] = [MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $trailerExternalMOID,
                MEDIA_OBJECT_INPUTS_ORDINAL_NO => 3,
                MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_PARENT
            ];
        }

        $mediaObject->setParents($parentsData);

        $mediaObject->calculateSubclass();
        $result = MOUtils::checkMediaObjectUniqueness(0, $mediaObject->getMediaClassID(), $mediaObject->getTitleID(), $mediaObject->getTitleVersionID(), $mediaObject->getLanguageID(), $mediaObject->getSubclass(), null);
        if ($result === true) {
            $mediaObject->setCreatedDate(Database::asSQLDateTime(time()));
            $mediaObject->insert();
            $compositeMOID = $mediaObject->getID();
            MediaObjectInputs::updateMediaObjectParents($mediaObject->getID(), $parentsData);
            $this->createdCompositeMOs[$mediaObject->getID()] = $mediaObject->getID();
        } else {
            $this->foundCompositeMOs[$result] = $result;
            $compositeMOID = $result;
        }

        // if there is a composite CPL created then the DP will reference  the composite CPL, otherwise the  VF CPL
        $this->dcPackages[$MO->getID()]["input_id"] = $compositeMOID;

    }
    /**
     * @param $moData
     * @throws DataNotFoundException
     * @throws DatabaseErrorException
     * @throws Exception
     * @throws IllegalArgumentException
     */
    private function createDCDPMediaObject($moData) {

        $parentsData = [[MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT => $moData["input_id"],
            MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
            MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_PARENT
        ]];

        $moName = $moData["name"]."-dp";

        $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_DISTRIBUTION_PACKAGE);
        $mediaObject->setName($moName);
        $mediaObject->setTitleID($this->params[self::TITLE_ID]);
        $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
        $mediaObject->setLanguageID($moData["languageID"]);

        $mediaObject->setParents($parentsData);

        $mediaObject->calculateSubclass();
        $result = MOUtils::checkMediaObjectUniqueness(0, $mediaObject->getMediaClassID(), $mediaObject->getTitleID(), $mediaObject->getTitleVersionID(), $mediaObject->getLanguageID(), $mediaObject->getSubclass(), null);
        if ($result === true) {
            $mediaObject->setCreatedDate(Database::asSQLDateTime(time()));
            $mediaObject->insert();
            MediaObjectInputs::updateMediaObjectParents($mediaObject->getID(), $parentsData);
            $this->createdDPMOs[$mediaObject->getID()] = $mediaObject->getID();
            $dpMOID = $mediaObject->getID();
        } else {
            $this->foundDPMOs[$result] = $result;
            $dpMOID = $result;
        }

        // DP Wailua

        $parentsData = [[MEDIA_OBJECT_INPUTS_MEDIA_OBJECT_INPUT =>  $dpMOID,
            MEDIA_OBJECT_INPUTS_ORDINAL_NO => null,
            MEDIA_OBJECT_INPUTS_ROLE => MEDIA_OBJECT_INPUTS_ROLE_PARENT
        ]];

        $moName = $moData["name"]."-dpWailua";

        $mediaObject = new MediaObject(MEDIA_CLASS_ID_DC_DP_WAILUA);
        $mediaObject->setName($moName);
        $mediaObject->setTitleID($this->params[self::TITLE_ID]);
        $mediaObject->setTitleVersionID($this->params[self::TITLE_VERSION_ID]);
        $mediaObject->setLanguageID($moData["languageID"]);

        $mediaObject->setParents($parentsData);

        $mediaObject->calculateSubclass();
        $result = MOUtils::checkMediaObjectUniqueness(0, $mediaObject->getMediaClassID(), $mediaObject->getTitleID(), $mediaObject->getTitleVersionID(), $mediaObject->getLanguageID(), $mediaObject->getSubclass(), null);
        if ($result === true) {
            $mediaObject->setCreatedDate(Database::asSQLDateTime(time()));
            $mediaObject->insert();
            MediaObjectInputs::updateMediaObjectParents($mediaObject->getID(), $parentsData);
            $this->createdDPWMOs[$mediaObject->getID()] = $mediaObject->getID();
        } else {
            $this->foundDPWMOs[$result] = $result;
        }


    }

    /**
     * @param $moID
     * @param $baseCPLInputs
     * @return bool
     */
    private function isOVCPLInput ($moID, $baseCPLInputs) {
        foreach ($baseCPLInputs as $data) {
            if ($data[MediaObject::ID] == $moID) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param MediaObject $mediaObject
     * @param $mcAttributes
     * @param $dim
     * @param $haveSub
     */
    private function setMOAttributes(MediaObject $mediaObject, $mcAttributes, $dim = '',$haveSub)
    {
        $attrs = [];
        $name = $mediaObject->getName();
        if (preg_match("/_(IOP|SMPTE)/i", $name, $matches)) {
            $attrs["DC_Format"] = strtoupper($matches[1]);
        }

        $attrs["Base_Name"] = $name;

        $terr = $this->getTerritory($name);
        if ($terr) {
            $attrs["Territory"] = $terr;
        }

        // populate from Title Info MO
        foreach ($mcAttributes as $attr) {
            if ($attr["titleMOattr"]) {
                $attrVal = $this->titleInfoMO->getAttributeByName($attr["titleMOattr"]);
                if ($attrVal === null) {
                    // not found in source MO
                    continue;
                }
                $attrs[$attr["name"]] = $attrVal;
            }
        }

        //get Burned_In
        $BurnedIn = "false";
        //don't have a subtitle input then set to false
        if($haveSub){
            //if a DCP composition name has CCAP in it, then the burned in attribute is false regardless of other factors such as 3D and lower case letters.
            if(!preg_match("/CCAP/i",$name)){
                if (preg_match("/_([A-Za-z\-]+)_([A-Za-z\-]+)_(51|71)/i", $name, $matches) ) {
                    if(preg_match('/[a-z]+/', $matches[1])){
                        $BurnedIn = "true";
                    }
                }
                if($dim == "3D"){
                    $BurnedIn = "true";
                }
            }
        }
        $attrs["Burned_In"] = $BurnedIn;
        $mediaObject->setAttributesByName($attrs);
    }

    /**
     * @param $name
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
     * @param $format
     * @param $isDCDM
     * @return mixed|string
     */
    private function getDCFormat($format,$isDCDM = false) {

        /*  <Attribute>
            <Input Type="Select" Name="DC_Format" Required="1" Unique="1">
              <Option Value="IOP">IOP</Option>
              <Option Value="SMPTE">SMPTE</Option>
            </Input>
          </Attribute>*/

        if($isDCDM && $this->params[self::SOURCE_TYPE] == 'DCDM'){
            $result = $this->params[self::SOURCE_TYPE];
            return $result;
        }
        
        $result = "";
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
     * @param $data
     * @return array|null
     * @throws Exception
     */
    private function validateCSV($data) {
        $columns = [];
        $dataCols = ["name" => "DCP COMPOSITION NAME",
                    "parent_ref" => '^PARENT \/ REFERENCE$',
                    "territory" => '^TERRITORY \(DDSS\)$',
                    "picture" => '^PICTURE$',
                    "dim" => '^DIM.$',
                    "standard" => '^STANDARD$',
                    "language" => '^AUDIO LANGUAGE$',
                    "audio_format" => '^AUDIO FORMAT$',
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
                $colName = preg_replace('/[^A-Za-z0-9\s]/', '', $value);
                throw new Exception("$colName column not found");
            }
        }

        $columns = array_flip($columns);

        return $columns;

    }

}
