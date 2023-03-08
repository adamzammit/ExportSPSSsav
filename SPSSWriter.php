<?php

/* Creates a file containing responses in the SAV (native SPSS binary format). Uses: https://github.com/tiamo/spss/ to do so
 * In contrast to importing a plain CSV or xls-file, the data is fully labelled with variable- and value labels.
 * Date and time strings are converted to SPSSs time format (seconds since midnight, 14 October 1582), so they can be directly used in calculations
 * Limitations:
 *  SPSS versions through 13? only support strings up to 256 bytes, version 14 up to 32767 bytes.....longer answers (ie. text fields) will be cut.
 */

use SPSS\Sav\Variable;


class SPSSWriter extends Writer
{
    private $output;
    private $separator;
    private $hasOutputHeader;

    /**
     * The open filehandle
     */
    protected $handle = null;
    protected $customFieldmap = array();
    protected $customResponsemap = array();
    protected $headers = array();
    protected $headersSGQA = array();
    protected $aQIDnonumericalAnswers = array();
    protected $recodeOther = 997;
    protected $recodeNArray = false;
    protected $recodeNMulti = true;
    protected $multipleChoiceData = array();
    protected $yvalue = 'Y';
    protected $nvalue = 'N';


    function __construct($pluginsettings)
    {
        $this->output          = '';
        $this->separator       = ',';
        $this->hasOutputHeader = false;
        $this->spssfileversion = $pluginsettings['spssfileversion']['current'];


        if ($this->spssfileversion >= 13) {
            $this->maxStringLength = 32767; // for SPSS version 13 and above
        } else {
            $this->maxStringLength = 255; // for older SPSS versions
        }
    }

    public function init(SurveyObj $survey, $sLanguageCode, FormattingOptions $oOptions)
    {
        parent::init($survey, $sLanguageCode, $oOptions);
        if ($oOptions->output == 'display') {
            header("Content-Disposition: attachment; filename=survey_".$survey->id."_spss.sav");
            header("Content-type: application/download; charset=UTF-8");
            header("Cache-Control: must-revalidate, no-store, no-cache");
            $this->handle = fopen('php://output', 'w');
        } elseif ($oOptions->output == 'file') {
            $this->handle = fopen($this->filename, 'w');
        }
        $this->headersSGQA       = $oOptions->selectedColumns;
        //$oOptions->headingFormat = 'code'; // Always use fieldcodes
        $oOptions->answerFormat = "short"; // force answer codes
        $this->customFieldmap = $this->createSPSSFieldmap($survey, $sLanguageCode, $oOptions);
    }


    /**
     * @param string $content
     */
    protected function out($content)
    {
        fwrite($this->handle, $content."\n");
    }


    /* Returns an array with vars, labels, survey info
     * For SPSS sav files using, we basically need:
     * Some things depending on the responses (eg. SPSS data type and format, some reoding),
     * are done later in updateResponsemap()
     */

    /**
     * @param SurveyObj $survey
     * @param string $sLanguage
     * @param FormattingOptions $oOptions
     * @return mixed
     */
    function createSPSSFieldmap($survey, $sLanguage, $oOptions)
    {
        App()->setLanguage($sLanguage);

        $this->yvalue = $oOptions->convertY ? $oOptions->yValue : 'Y';
        $this->nvalue = $oOptions->convertN ? $oOptions->nValue : 'N';

        //create fieldmap only with the columns (variables) selected
        $aFieldmap['questions'] = array_intersect_key($survey->fieldMap, array_flip($oOptions->selectedColumns));

        //tokens need to be "smuggled" into the fieldmap as additional questions
        $aFieldmap['tokenFields'] = array_intersect_key($survey->tokenFields, array_flip($oOptions->selectedColumns));
        foreach ($aFieldmap['tokenFields'] as $key=>$value) {
            $aFieldmap['questions'][$key] = $value;
            $aFieldmap['questions'][$key]['qid'] = '';
            $aFieldmap['questions'][$key]['question'] = $value['description'];
            $aFieldmap['questions'][$key]['fieldname'] = $key;
            $aFieldmap['questions'][$key]['type'] = 'S';
        }
        // add only questions and answers to the fieldmap that are relevant to the selected columns (variables)
        foreach ($aFieldmap['questions'] as $question) {
            $aUsedQIDs[] = $question['qid'];
        }
        $aFieldmap['answers'] = array_intersect_key($survey->answers, array_flip($aUsedQIDs));

        // add per-survey info
        $aFieldmap['info'] = $survey->info;

        // go through the questions array and create/modify vars for SPSS-output
        foreach ($aFieldmap['questions'] as $sSGQAkey => $aQuestion) {

        
    
            //get SPSS output type if selected
            $aQuestionAttribs = QuestionAttribute::model()->getQuestionAttributes($aQuestion['qid'],$sLanguage);
            if (isset($aQuestionAttribs['scale_export'])) {
                    $export_scale = $aQuestionAttribs['scale_export'];
                    $aFieldmap['questions'][$sSGQAkey]['spssmeasure'] = $export_scale;
            }

            // create 'varname' from Question/Subquestiontitles
            $aQuestion['varname'] = viewHelper::getFieldCode($aFieldmap['questions'][$sSGQAkey]);

            //set field types for standard vars
            if ($aQuestion['varname'] == 'submitdate' || $aQuestion['varname'] == 'startdate' || $aQuestion['varname'] == 'datestamp') {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'D';
            } elseif ($aQuestion['varname'] == 'startlanguage') {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'S';
            } elseif ($aQuestion['varname'] == 'token') {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'S';
            } elseif ($aQuestion['varname'] == 'id') {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'N';
            } elseif ($aQuestion['varname'] == 'ipaddr') {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'S';
            } elseif ($aQuestion['varname'] == 'refurl') {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'S';
            } elseif ($aQuestion['varname'] == 'lastpage') {
                $aFieldmap['questions'][$sSGQAkey]['type'] = 'N';
            }

            $value= $aQuestion['question'];
            $sColumn = $aQuestion['fieldname'];
            switch ($oOptions->headingFormat) {
                case 'abbreviated':
                    $value = $this->getAbbreviatedHeading($survey, $oOptions, $sColumn);
                    break;
                case 'full':
                    $value = $this->getFullHeading($survey, $oOptions, $sColumn);
                    break;
                case 'codetext':
                    $value = $this->getHeadingCode($survey, $oOptions, $sColumn).$oOptions->headCodeTextSeparator.$this->getHeadingText($survey, $oOptions, $sColumn);
                    break;
                case 'code':
                default:
                    $value = $this->getHeadingCode($survey, $oOptions, $sColumn);
                    break;
            }
            $aQuestion['varlabel'] = $value;

            //Rename the variables if original name is not SPSS-compatible
            $aQuestion['varname'] = $this->SPSSvarname($aQuestion['varname']);

            //write varlabel back to fieldmap
            $aFieldmap['questions'][$sSGQAkey]['varlabel'] = $aQuestion['varlabel'];

            //create value labels for question types with "fixed" answers (YES/NO etc.)
            if ((isset($aQuestion['other']) && $aQuestion['other'] == 'Y') || substr($aQuestion['fieldname'], -7) == 'comment') {
                $aFieldmap['questions'][$sSGQAkey]['commentother'] = true; //comment/other fields: create flag, so value labels are not attached (in close())
                if (substr($aQuestion['fieldname'], -5) == 'other') {
                    $tmpfn = substr($aQuestion['fieldname'],0, -5);
                    if (isset($aFieldmap['questions'][$tmpfn])) {
                        $aFieldmap['questions'][$tmpfn]['hasother'] = true;
                    }
                }
            } else {
                $aFieldmap['questions'][$sSGQAkey]['commentother'] = false;


                if ($aQuestion['type'] == 'M') {
                    $aFieldmap['answers'][$aQuestion['qid']]['0'][$this->yvalue] = array(
                        'code' => $this->yvalue,
                        'answer' => gT('Yes')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0'][$this->nvalue] = array(
                        'code' => $this->nvalue,
                        'answer' => gT('No')
                    );
                } elseif ($aQuestion['type'] == ":") { //array numeric .. check if multiflex checkbox
                    $qidattributes=QuestionAttribute::model()->getQuestionAttributes($aQuestion['qid']);
                    $aFieldmap['questions'][$sSGQAkey]['multiflexible_checkbox'] = false;
                    if (isset($qidattributes['multiflexible_checkbox']) && $qidattributes['multiflexible_checkbox'] == 1) {
                        $aFieldmap['questions'][$sSGQAkey]['multiflexible_checkbox'] = true;
                        $aFieldmap['answers'][$aQuestion['qid']]['0'][$this->yvalue] = array(
                            'code' => $this->yvalue,
                            'answer' => gT('Yes')
                        );
                        $aFieldmap['answers'][$aQuestion['qid']]['0'][$this->nvalue] = array(
                            'code' => $this->nvalue,
                            'answer' => gT('No')
                        );
                    }
                } elseif ($aQuestion['type'] == "P") {
                    $aFieldmap['answers'][$aQuestion['qid']]['0'][$this->yvalue] = array(
                        'code' => $this->yvalue,
                        'answer' => gT('Yes')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0'][$this->nvalue] = array(
                        'code' => $this->nvalue,
                        'answer' => gT('No')
                    );
                } elseif ($aQuestion['type'] == "G") {
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['0'] = array(
                        'code' => 'F',
                        'answer' => gT('Female')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['1'] = array(
                        'code' => 'M',
                        'answer' => gT('Male')
                    );
                } elseif ($aQuestion['type'] == "Y") {
                    $aFieldmap['answers'][$aQuestion['qid']]['0'][$this->yvalue] = array(
                        'code' => $this->yvalue,
                        'answer' => gT('Yes')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0'][$this->nvalue] = array(
                        'code' => $this->nvalue,
                        'answer' => gT('No')
                    );
                } elseif ($aQuestion['type'] == "C") {
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['1'] = array(
                        'code' => 1,
                        'answer' => gT('Yes')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['0'] = array(
                        'code' => 2,
                        'answer' => gT('No')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['9'] = array(
                        'code' => 3,
                        'answer' => gT('Uncertain')
                    );
                } elseif ($aQuestion['type'] == "E") {
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['1'] = array(
                        'code' => 1,
                        'answer' => gT('Increase')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['0'] = array(
                        'code' => 2,
                        'answer' => gT('Same')
                    );
                    $aFieldmap['answers'][$aQuestion['qid']]['0']['-1'] = array(
                        'code' => 3,
                        'answer' => gT('Decrease')
                    );
                }
            } // close: no-other/comment variable
        $aFieldmap['questions'][$sSGQAkey]['varname'] = $aQuestion['varname']; //write changes back to array
        } // close foreach question


        // clean up fieldmap (remove HTML tags, CR/LS, etc.)
        $aFieldmap = $this->stripArray($aFieldmap);
        return $aFieldmap;
    }


    /*  return a SPSS-compatible variable name
     *    strips some special characters and fixes variable names starting with a number
     */
    protected function SPSSvarname($sVarname)
    {
        if (!preg_match("/^([a-z]|[A-Z])+.*$/", $sVarname)) {
//var starting with a number?
            $sVarname = "v".$sVarname; //add a leading 'v'
        }
        $sVarname = str_replace(array(
            "-",
            ":",
            ";",
            "!",
            "[",
            "]",
            " "
        ), array(
            "_",
            "_dd_",
            "_dc_",
            "_excl_",
            "_",
            "",
            "_"
        ), $sVarname);
        return $sVarname;
    }


    /*  strip html tags, blanks and other stuff from array, flattens text
     */
    protected function stripArray($tobestripped)
    {
        Yii::app()->loadHelper('export');
        function clean(&$item)
        {
            if (is_string($item)){
            $item = trim((htmlspecialchars_decode(stripTagsFull($item))));
            }

        }
        array_walk_recursive($tobestripped, 'clean');
        return ($tobestripped);
    }


    /* Function is called for every response
     * Here we just use it to create arrays with variable names and data
     */
    protected function outputRecord($headers, $values, FormattingOptions $oOptions)
    {
        // function is called for every response to be exported....only write header once
        if (empty($this->headers)) {
            $this->headers = $headers;
            foreach ($this->headers as $iKey => &$sVarname) {
                $this->headers[$iKey] = $this->SPSSvarname($sVarname);
            }
        }
        // gradually fill response array...
        $this->customResponsemap[] = $values;
    }

    /*
    This function updates the fieldmap and recodes responses
    so output to XML in close() is a piece of cake...
    */
    protected function updateCustomresponsemap()
    {
        include_once(dirname(__FILE__) . "/helpers/spss/vendor/autoload.php");

        //go through each particpants' responses
        foreach ($this->customResponsemap as $iRespId => &$aResponses) {
            // go through variables and response items


            //relevant types for SPSS are numeric (need to know largest number and number of decimal places), date and string
            foreach ($aResponses as $iVarid => &$response) {
                $isnull = is_null($response);
                $response = trim($response);
                $iDatatype = 5;
                $iStringlength = 1;
                if ($response != '') {

                    //if this is a multiple choice question with an other - store the data in a tmp variable for additional export
                    if ($this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['type'] == 'M' && $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['commentother'] == true ) {
                        if ($response == $this->nvalue) {
                            $this->multipleChoiceData[$iVarid][$iRespId] = $this->nvalue;
                            $response = "";
                        } else {
                            $this->multipleChoiceData[$iVarid][$iRespId] = $this->yvalue;
                        }
                    }

                    //if this is a multiflex checkbox recode
                    if ($this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['type'] == ':' && $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['multiflexible_checkbox'] == true ) {
                        if ($response != 0) {
                            $response = $this->yvalue;
                        }
                    }


                    if ($response == '-oth-') {
                        $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['spssothervaluelabel'] = true;
                        if ($this->recodeOther != false) {
                            $response = $this->recodeOther;
                        }
                    }

                    $numberresponse = trim($response);
                    if ($this->customFieldmap['info']['surveyls_numberformat'] == 1) {
                        // if settings: decimal seperator==','
                        $numberresponse = str_replace(',', '.', $response); // replace comma with dot so SPSS can use decimal variables
                    }

                   if ($this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['type'] == 'D') {
                    $date = new DateTimeImmutable($response.' GMT');
                    $spssepoch = new DateTimeImmutable('1582-10-14 00:00:00 GMT');
                    $response = $date->getTimestamp() - $spssepoch->getTimestamp(); //convert to full SPSS date format which is the number of seconds since midnight October 14, 1582
                    $iDatatype = 3;
                   } else if (is_numeric($numberresponse)) {
                        // deal with numeric responses/variables
                        if (ctype_digit($numberresponse)) {
                            // if it contains only digits (no dot) --> non-float number (set decimal places to 0)
                                $iDatatype = 2; 
                                $iDecimalPlaces = 0;
                                $iNumberWidth = strlen($response);
                            } else {
                                if ($this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['type'] == 'D') {
                                    // if datefield then a date datafiled
                                    $iDatatype = 3; //date
                        } else {
//non-integer numeric response
                            $iDatatype = 2; // float
                            $iDecimalPlaces = 1;
                            $response = $numberresponse; //replace in customResponsemap: value with '.' as decimal
                            $tmpdpoint = strpos($response,".");
                            $iDecimalPlaces = 2;
                            $iNumberWidth = strlen($response); //just to be safe
                            if ($tmpdpoint !== false) {
                                $iNumberWidth = strlen($response);
                                $iDecimalPlaces = $iNumberWidth - ($tmpdpoint + 1);
                            }
                            
                        }
                        }
                    } else {
// non-numeric response
                        $iDatatype = 1; //string
                        if (strlen($response) > 0) {
                            $iStringlength = strlen($response); //for strings we need the length for the format and the data type
                        }
                    }
                } else {
                    //check if at least one answered in group
                    $checktype = $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['type'];

                    $oneanswered = false;

                    if ($checktype == ":" || $checktype == "M") {
                        $checksid = $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['sid'];
                        $checkgid = $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['gid'];
                        $checkqid = $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['qid'];
                        $checksqid = $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['sqid'];
                        $checki = 0;
                        foreach($this->customFieldmap['questions'] as $checkq) {
                            if ($checkq['sid'] == $checksid &&
                                $checkq['gid'] == $checkgid &&
                                $checkq['qid'] == $checkqid &&
                                $checkq['sqid'] != $checksqid) { //a question in this group that is not this question
                                if (trim($aResponses[$checki]) != '') {
                                    $oneanswered = true;
                                    break;
                                }
                            }
                            $checki++;
                        }
                    }

                    //if this is a multiple choice response, or a  multiflex checkbox recode empty responses as Nvalue
                    //leave as null if all questions in the group are null
                    //code as nvalue if at least one is answered and not null
                    if ($this->recodeNArray && $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['type'] == ':'
                        && $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['multiflexible_checkbox'] == true
                        && (!$isnull || $oneanswered)) {
                        $response = $this->nvalue;
                    }
                    if ($this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['commentother'] == true
                        && $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['type'] == 'M') { //ensure default response brought across
                        $this->multipleChoiceData[$iVarid][$iRespId] = $response;
                    }
                    if ($this->recodeNMulti && $this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['type'] == 'M' && (!$isnull || $oneanswered)) {
                        if ($this->customFieldmap['questions'][$this->headersSGQA[$iVarid]]['commentother'] == true) {
                            $this->multipleChoiceData[$iVarid][$iRespId] = $this->nvalue;
                        } else {
                            $response = $this->nvalue;
                        }
                    }
                }

                // initialize format and type (default: empty)
                if (!isset($aSPSStypelist[$this->headersSGQA[$iVarid]]['type'])) {
                                    $aSPSStypelist[$this->headersSGQA[$iVarid]]['type'] = 5;
                }
                if (!isset($aSPSStypelist[$this->headersSGQA[$iVarid]]['format'])) {
                                    $aSPSStypelist[$this->headersSGQA[$iVarid]]['format'] = 0;
                }
                if (!isset($aSPSStypelist[$this->headersSGQA[$iVarid]]['decimals'])) {
                                    $aSPSStypelist[$this->headersSGQA[$iVarid]]['decimals'] = -1;
                }
                
                // Does the variable need a higher datatype because of the current response?
                if ($iDatatype < $aSPSStypelist[$this->headersSGQA[$iVarid]]['type'] ) {
                                    $aSPSStypelist[$this->headersSGQA[$iVarid]]['type'] = $iDatatype;
                }
                
                // if datatype is a string, set needed stringlength
                if ($aSPSStypelist[$this->headersSGQA[$iVarid]]['type'] == 1 || $aSPSStypelist[$this->headersSGQA[$iVarid]]['type'] == 5) {
                    $aSPSStypelist[$this->headersSGQA[$iVarid]]['decimals'] = -1;
                    // Does the variable need a higher stringlength because of the current response?
                    if ($aSPSStypelist[$this->headersSGQA[$iVarid]]['format'] < $iStringlength) {
                                            $aSPSStypelist[$this->headersSGQA[$iVarid]]['format'] = $iStringlength;
                    }
                    
                }
                 // if datatype is a numeric, set needed width and decimals
                if ($aSPSStypelist[$this->headersSGQA[$iVarid]]['type']  == 2) {
                    // Does the variable need a higher length because of the current response?
                    if ($aSPSStypelist[$this->headersSGQA[$iVarid]]['format'] < $iNumberWidth) {
                                            $aSPSStypelist[$this->headersSGQA[$iVarid]]['format'] = $iNumberWidth;
                    }
                     if ($aSPSStypelist[$this->headersSGQA[$iVarid]]['decimals'] < $iDecimalPlaces) {
                                            $aSPSStypelist[$this->headersSGQA[$iVarid]]['decimals'] = $iDecimalPlaces;
                    }
                    
                }
                //write the recoded response back to the response array
                $this->customResponsemap[$iRespId][$iVarid] = $response;
            }
        }


        // translate coding into SPSS datatypes, format and length
        foreach ($aSPSStypelist as $variable => $data) {

            switch ($data['type']) {
                case 5:
                case 1: 
                    $this->customFieldmap['questions'][$variable]['spsswidth']   = min($data['format'], $this->maxStringLength);
                    $this->customFieldmap['questions'][$variable]['spssformat'] = Variable::FORMAT_TYPE_A;
                    $this->customFieldmap['questions'][$variable]['spssalignment'] = Variable::ALIGN_LEFT;
                    if (!isset($this->customFieldmap['questions'][$variable]['spssmeasure'])) {
                        $this->customFieldmap['questions'][$variable]['spssmeasure'] = Variable::MEASURE_NOMINAL;
                    }
                    $this->customFieldmap['questions'][$variable]['spssdecimals'] = -1;
                    break;
                case 2: 
                    $this->customFieldmap['questions'][$variable]['spsswidth']   = $data['format'];
                    $this->customFieldmap['questions'][$variable]['spssformat'] = Variable::FORMAT_TYPE_F;
                    $this->customFieldmap['questions'][$variable]['spssdecimals'] = $data['decimals'];
                    $this->customFieldmap['questions'][$variable]['spssalignment'] = Variable::ALIGN_LEFT;
                    if (!isset($this->customFieldmap['questions'][$variable]['spssmeasure'])) {
                        $this->customFieldmap['questions'][$variable]['spssmeasure'] = Variable::MEASURE_NOMINAL;
                    }
                    break;
                case 3: 
                    $this->customFieldmap['questions'][$variable]['spsswidth']   = 20;
                    $this->customFieldmap['questions'][$variable]['spssformat'] = Variable::FORMAT_TYPE_DATETIME;
                    $this->customFieldmap['questions'][$variable]['spssalignment'] = Variable::ALIGN_LEFT;
                    if (!isset($this->customFieldmap['questions'][$variable]['spssmeasure'])) {
                        $this->customFieldmap['questions'][$variable]['spssmeasure'] = Variable::MEASURE_NOMINAL;
                    }
                    $this->customFieldmap['questions'][$variable]['spssdecimals'] = -1;
                    break;
            }
        }
    }

    /* Output SPSS sav code using library
     */
    public function close()
    {

        $this->updateCustomresponsemap();

        include_once(dirname(__FILE__) . "/helpers/spss/vendor/autoload.php");

        $variables = array();

        foreach ($this->customFieldmap['questions'] as $question) {
            //if this is a multiple choice 'other' question, add a new column in advance
            if ($question['commentother'] == true && $question['type'] == 'M') {
                $tmpvar = array();
                $tmpvar['name'] = $question['varname'] . 'c';
                $tmpvar['format'] = Variable::FORMAT_TYPE_F;
                $tmpvar['width'] = 1;
                $tmpvar['decimals'] = 0;
                $tmpvar['alignment'] = Variable::ALIGN_LEFT;
                $tmpvar['columns'] = 8;
                $tmpvar['label'] = $question['varlabel'];        
                $tmpvar['measure'] = Variable::MEASURE_NOMINAL;   
                $tmpvar['values'][$this->yvalue] = gT('Yes');
                $tmpvar['values'][$this->nvalue] = gT('No');
                if (!is_numeric($this->yvalue) || !is_numeric($this->nvalue)) {
                    $tmpvar['width'] = 28;
                    $tmpvar['format'] = Variable::FORMAT_TYPE_A;
                }
                $variables[] = $tmpvar;
            }

            $tmpvar = array();
            $tmpvar['name'] = $question['varname'];       
            $tmpvar['format'] = $question['spssformat'];
            $tmpvar['width'] = $question['spsswidth'];
            if ($question['spssdecimals'] > -1)
            {
                $tmpvar['decimals'] = $question['spssdecimals'];        
            }
            $tmpvar['label'] = $question['varlabel'];        
            $tmpwidth = $question['spsswidth'];
            //export value labels if they exist (not for time questions)
            if (!empty($this->customFieldmap['answers'][$question['qid']]) && $question['commentother'] == false && $question['type'] != "answer_time") {
                $tmpvar['values'] = array();
                foreach($this->customFieldmap['answers'][$question['qid']] as $aAnswercodes) {
                    foreach($aAnswercodes as $sAnscode => $aAnswer) {
                        $tmpans = "";
                        if (is_array($aAnswer) && isset($aAnswer['answer'])) {
                            $tmpans = $aAnswer['answer'];
                        } else {
                            $tmpans = $aAnswer;
                        }
                        $tmpvar['values'][$sAnscode] = $tmpans;
                        if(!is_numeric($sAnscode))  {
                            if ($tmpwidth < 28) $tmpwidth = 28; // SPSS wants variable width wide where string data stored
                        }
                    }
                }

                //if other is set or expected, add as value label
                if ((isset($question['spssothervaluelabel']) && $question['spssothervaluelabel'] == true) ||
                    (isset($question['hasother']) && $question['hasother'] == true)
                    ) {
                    $othvalue = '-oth-';
                    if ($this->recodeOther != false) {
                        $othvalue = $this->recodeOther;
                    }
                    $tmpvar['values'][$othvalue] = "Other";
                }
            }
            $tmpvar['width'] = $tmpwidth;
            $tmpvar['columns'] = 8;
            $tmpvar['alignment'] = $question['spssalignment'];
            $tmpvar['measure'] = $question['spssmeasure'];
            $variables[] = $tmpvar;
        }

        $header = array(
            'prodName' => '@(#) IBM SPSS STATISTICS 64-bit Macintosh 23.0.0.0',
            'creationDate' => date('d M y'),
            'creationTime' => date('H:i:s'),
            'weightIndex' => 0,
       );

        $info = array(
             'machineInteger' => [
                 'machineCode' => 720,
                 'version' => [23, 0, 0],
             ],
             'machineFloatingPoint' => [
                 'sysmis' => -1.7976931348623157e+308,
                 'highest' => 1.7976931348623157e+308,
                 'lowest' => -1.7976931348623155e+308,
             ],
        );

        $writer = new \SPSS\Sav\Writer(['header' => $header, 'info' => $info, 'variables' => $variables]);

        foreach ($this->customResponsemap as $iResponseid => $aResponses) {
            $tmpdat = array();
            foreach ($aResponses as $iVarid => $response) {
                if (isset($this->multipleChoiceData[$iVarid])) {
                    $mr = "";
                    if (isset($this->multipleChoiceData[$iVarid][$iResponseid])) {
                        $mr = $this->multipleChoiceData[$iVarid][$iResponseid];
                    }
                    $tmpdat[] = $mr;
                }
                $tmpdat[] = $response;
            }
            $writer->writeCase($tmpdat);
        }


        //write to temporary file then remove
        $tmpfile = tempnam(Yii::app()->getConfig("tempdir"), "SPSS");
        $writer->save($tmpfile);
        $writer->close();
        echo(file_get_contents($tmpfile));
        unlink($tmpfile);

        fclose($this->handle);
    }
}
