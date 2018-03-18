<?php
/**
 * Plugin Name: Sixthlife Course
 * Version: 4.0.1
 * Plugin URI: http://sixthlife.net
 * Description: WP Courseware extension to enhance import export quiz
 * Author: Sixthlife
 * Author URI: http://sixthlife.net
 *
 * Copyright 2016-2016 Sixthlife 
 */
define("PATH_ROOT", str_replace('sixcourse-addon-for-','' ,dirname(__FILE__)));
add_action('init', 'sixco_import_tweak');
		
function sixco_import_tweak(){
global $wp, $wpdb, $page ;
ob_start();
// Main parent class
 if (is_admin() && is_dir(PATH_ROOT)) {
	 include_once PATH_ROOT .'/classes/class_quiz_base.inc.php';
	include_once PATH_ROOT .'/classes/class_quiz_upload.inc.php';
	include_once PATH_ROOT .'/lib/data_class_import.inc.php';
	include_once PATH_ROOT .'/pages/page_import_export.inc.php';
	// External Libs
	include_once PATH_ROOT .'/wplib/utils_pagebuilder.inc.php';
	include_once PATH_ROOT .'/wplib/utils_recordsform.inc.php';
	include_once PATH_ROOT .'/wplib/utils_tablebuilder.inc.php';
	// Plugin-specific
	include_once PATH_ROOT .'/lib/admin_only.inc.php';
	include_once PATH_ROOT .'/lib/export_data.inc.php';
	include_once PATH_ROOT .'/lib/class_courses_map.inc.php';

	// Data
	include_once PATH_ROOT .'/lib/data_class_export.inc.php';
	include_once PATH_ROOT .'/lib/data_class_import.inc.php';

	// Templates
	include_once PATH_ROOT .'/lib/templates_backend.inc.php';
	}
else{
	return false;
	}
	sixco_importquestions_modified($page);
}
 
 		 
function sixco_importquestions_modified($page){
	global $wp, $wpdb, $wpcwdb;
	$page = new PageBuilder(true);
	if(isset($_FILES) && isset($_FILES ['import_questions_csv'])){
	set_time_limit( 0 );
	$page->showMessage( __( 'Import started...', 'wp_courseware' ) );
//	flush();

	if ( isset( $_FILES[ 'import_questions_csv' ][ 'name' ] ) ) {
		// See what type of file we're tring to upload
		$type      = strtolower( $_FILES[ 'import_questions_csv' ][ 'type' ] );
		$isexcel = false;
		$fileTypes = array(
			'text/csv',
			'text/plain',
			'application/csv',
			'text/comma-separated-values',
			'application/excel',
			'application/vnd.ms-excel',
			'application/vnd.msexcel',
			'text/anytext',
			'application/octet-stream',
			'application/txt'
			);
			
			if($type=='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
			{
				$isexcel = true;
			}
//echo $type." "; exit;
		if ( ! in_array( $type, $fileTypes ) && $isexcel==false ) {
			$page->showMessage( __( 'Unfortunately, you tried to upload a file that isn\'t a CSV or XLSX file.', 'wp_courseware' ), true );

			return false;
		}

		// Filetype is fine, carry on
		$errornum = $_FILES[ 'import_questions_csv' ][ 'error' ] + 0;
		$tempfile = $_FILES[ 'import_questions_csv' ][ 'tmp_name' ];

		// File uploaded successfully?
		if ( $errornum == 0 ) {
			
			if($isexcel){
			$tempfile = sixco_readexcel($_FILES[ 'import_questions_csv' ]); 
				if($tempfile==false){
				$page->showMessage( __( 'Unfortunately, you tried to upload a file that isn\'t a CSV or XLSX file or is corrupt.', 'wp_courseware' ), true );
				return false;
				}
			}			
			// Try the import, return error/success here
			if ( ( $csvHandle = fopen( $tempfile, "r" ) ) !== false ) {
				$assocData  = array();
				$rowCounter = 0;

				// Extract the user details from the CSV file into an array for importing.
				while ( ( $rowData = fgetcsv( $csvHandle, 0, "," ) ) !== false ) { //print_r($rowData); exit;
					if ( 0 === $rowCounter ) {
						$headerRecord = $rowData;
					} else {
						foreach ( $rowData as $key => $value ) {
							$assocData[ $rowCounter - 1 ][ trim( $headerRecord[ $key ] ) ] = $value;
						}
						$assocData[ $rowCounter - 1 ][ 'row_num' ] = $rowCounter + 1;
					}
					$rowCounter ++;
				}

				// Check we have users to process before continuing.
				if ( count( $assocData ) < 1 ) {
					$page->showMessage( __( 'No data was found in the CSV file, so there is nothing to do.', 'wp_courseware' ), true );

					return;
				}

				// Statistics for update.
				$count_newQuestion       = 0;
				$count_skippedButUpdated = 0;
				$count_aborted           = 0;

				// By now, $assocData contains a list of user details in an array.
				// So now we try to insert all of these questions into the system, and validate them all.
				$skippedList = array();
			//	print_r($assocData); exit;
				// Loop through each row
				foreach( $assocData as $csvRowKey => $csvRow ) {

					// Define Question
					$question = array();

					// Question Types
					$questionTypes = apply_filters( 'wpcw_import_questions_allowed_question_types', array( 'multi', 'truefalse', 'open', 'upload' ) );

					// Question Id
					$questionId = 0;

					// Question Answers
					$questionAnswers = array();

					// Question Corect Answers
					$questionCorrectAnswers = array();

					// Other Variables
					$questionAnswerType = '';
					$questionAnswerHint = '';
					$questionAnswerExplanation = '';
					$questionAnswerFileTypes = '';

					// Multi Random Count
					$questionMultiRandom = 0;
					$questionMultiRandomCount = 5;

					// Tags
					$questionTags = array();

					// See if we have a quiz question. If not, abort
					if ( ! isset( $csvRow[ 'quiz_question' ] ) || ( isset( $csvRow[ 'quiz_question' ] ) && empty( $csvRow[ 'quiz_question' ] ) ) ) {
						$skippedList[] = array(
							'id'      => $csvRowKey,
							'row_num' => $csvRow[ 'row_num' ],
							'aborted' => true,
							'reason'  => __( '"quiz_question" column is blank.', 'wp_courseware' )
						);
						$count_aborted++;
						continue;
					}

					// Check Question Type
					if ( ! isset( $csvRow[ 'question_type' ] ) || ( isset( $csvRow[ 'question_type' ] ) && empty( $csvRow[ 'question_type' ] ) ) ) {
						$skippedList[] = array(
							'id'      => $csvRowKey,
							'row_num' => $csvRow[ 'row_num' ],
							'aborted' => true,
							'reason'  => __( '"question_type" column is blank.', 'wp_courseware' )
						);
						$count_aborted++;
						continue;
					}

					// Check Question Type
					if ( isset( $csvRow[ 'question_type' ] ) && ! in_array( $csvRow[ 'question_type' ], $questionTypes ) ) {
						$skippedList[] = array(
							'id'      => $csvRowKey,
							'row_num' => $csvRow[ 'row_num' ],
							'aborted' => true,
							'reason'  => __( '"question_type" is not valid.', 'wp_courseware' )
						);
						$count_aborted++;
						continue;
					}

					// Check Possible Answers and Correct Answers according to type
					switch ( $csvRow[ 'question_type' ] ) {
						case 'multi':
							// Possible Answers
							if ( isset( $csvRow[ 'possible_answers' ] ) ) {
								$possibleAnswers = explode( '|', $csvRow[ 'possible_answers' ] );
								$possibleAnswersTemp = array();
								foreach ( $possibleAnswers as $possibleAnswerKey => $possibleAnswer ) {
									if ( $possibleAnswer ) {
										$possibleAnswersTemp[ $possibleAnswerKey ] = trim( stripslashes( $possibleAnswer ) );
										$questionAnswers[ $possibleAnswerKey ] = array( 'answer' => trim( stripslashes( $possibleAnswer ) ) );
									}
								}

								// Correct Answers - Only process if there are possible answers
								if ( isset( $csvRow[ 'correct_answer' ] ) ) {
									$correctAnswers = explode( '|', $csvRow[ 'correct_answer' ] );
									foreach( $correctAnswers as $correctAnswerKey => $correctAnswer ) {
										$tryCorrectAnswer = trim( stripslashes( $correctAnswer ) );
										foreach( $possibleAnswersTemp as $possibleCorrectAnswerKey => $possibleCorrectAnswer ) {
											if ( $tryCorrectAnswer === $possibleCorrectAnswer ) {
												$questionCorrectAnswers[] = $possibleCorrectAnswerKey + 1;
											}
										}
									}
								}
							} else {
								$questionAnswers = array( '1' => array( 'answer' => '' ), '2' => array( 'answer' => '' ) );
							}
							break;
						case 'open':
							// No answers for an open ended question
							$answerTypes = WPCW_quiz_OpenEntry::getValidAnswerTypes();
							$answerType = ( $csvRow[ 'answer_type' ] ) ? strtolower( $csvRow[ 'answer_type' ] ) : false;
							if ( $answerType && array_key_exists( $answerType, $answerTypes ) ) {
								$questionAnswerType = esc_attr( $answerType );
							} else {
								$questionAnswerType = 'single_line';
							}
							break;
						case 'upload' :
							if ( isset( $csvRow[ 'file_extensions' ] ) ) {
								$questionAnswerFileTypes = WPCW_files_cleanFileExtensionList( $csvRow[ 'file_extensions' ] );
								$questionAnswerFileTypes = implode( ',', $questionAnswerFileTypes );
							}
							break;
						case 'truefalse' :
							$trueFalseCorrectAnswer = ( isset( $csvRow[ 'correct_answer' ] ) ) ? strtolower( $csvRow[ 'correct_answer' ] ) : false;
							if ( $trueFalseCorrectAnswer && in_array( $trueFalseCorrectAnswer, array( 'true', 'false' ) ) ) {
								$questionCorrectAnswers = $trueFalseCorrectAnswer;
							} else {
								$skippedList[] = array(
									'id'      => $csvRowKey,
									'row_num' => $csvRow[ 'row_num' ],
									'aborted' => true,
									'reason'  => sprintf( __( 'The question "<strong>%s</strong>" does not have a valid answer of either "TRUE" or "FALSE"', 'wp_courseware' ), $csvRow[ 'quiz_question' ] )
								);
								$count_aborted++;
								continue 2;
							}
							break;
						default:
							break;
					}

					// Hints
					$questionAnswerHint = ( isset( $csvRow[ 'hint' ] ) ) ? esc_attr( $csvRow[ 'hint' ] ) : '';

					// Explanation
					$questionAnswerExplanation = ( isset( $csvRow[ 'explanation' ] ) ) ? esc_attr( $csvRow[ 'explanation' ] ) : '';

					// Encode Answers
					if ( ! empty( $questionAnswers ) ) {
						foreach ( $questionAnswers as $key => $data ) {
							$questionAnswers[ $key ][ 'answer' ] = base64_encode( $data[ 'answer' ] );
						}
					}

					// Populate Question
					$question = array(
						'question_type'                => $csvRow[ 'question_type' ],
						'question_question'            => stripslashes( $csvRow[ 'quiz_question' ] ),
						'question_answers'             => false,
						'question_data_answers'        => ( $questionAnswers ) ? maybe_serialize( $questionAnswers ) : '',
						'question_correct_answer'      => ( $questionCorrectAnswers ) ? maybe_serialize( $questionCorrectAnswers ) : '',
						'question_answer_type'         => $questionAnswerType,
						'question_answer_hint'         => stripslashes( $questionAnswerHint ),
						'question_answer_explanation'  => stripslashes( $questionAnswerExplanation ),
						'question_image'               => '',
						'question_answer_file_types'   => $questionAnswerFileTypes,
						'question_usage_count'         => 0,
						'question_expanded_count'      => 1,
						'question_multi_random_enable' => $questionMultiRandom,
						'question_multi_random_count'  => $questionMultiRandomCount
					);

					// All Good, create question
					$questionId = WPCW_handler_Save_Question( $question );
					$questionOrder =0;
					if(is_numeric($questionId) && $questionId>0 && isset($csvRow[ 'quiz_title' ]) &&!empty($csvRow[ 'quiz_title' ]))	{
			
					$quiz_title = trim( stripslashes( $csvRow[ 'quiz_title' ] ));
					$sixcouq = "select quiz_id from $wpcwdb->quiz where quiz_title='{$quiz_title}' ";
					$quiz_id = $wpdb->get_var($sixcouq); 	//print_r($quiz_id);  
					echo $quiz_id." This ". ($quiz_id<=0)." ". ($quiz_id==false)." ".($quiz_id==" "); //exit;
					if($quiz_id<=0|| $quiz_id==false||$quiz_id==""){ 
					$current_uid = get_current_user_id();	
					$sixcoui = "insert into $wpcwdb->quiz (quiz_title,quiz_desc, quiz_author, quiz_type, quiz_pass_mark) values('{$quiz_title}', '',{$current_uid},'quiz_noblock',50 ) ";
					$sixcour = $wpdb->query($sixcoui);	
					$quiz_id =	$wpdb->insert_id;
				//	echo $quiz_id; exit;	
					}
									$wpdb->query($wpdb->prepare("
					INSERT INTO $wpcwdb->quiz_qs_mapping
					(question_id, parent_quiz_id, question_order)
					VALUES (%d, %d, %d)
				", $questionId, $quiz_id, $count_newQuestion));	
				
				WPCW_questions_updateUsageCount($questionId);					
					}
					// Check Tags
					if ( isset( $csvRow[ 'tags' ] ) && ! empty( $csvRow[ 'tags' ] ) ) {
						$tags = explode( ',', $csvRow[ 'tags' ] );
						foreach ( $tags as $tag ) {
							$questionTags[] = $tag;
						}
					}

					// Add Tags
					if ( ! empty( $questionTags ) && isset( $questionId ) && $questionId !== 0 ) {
						WPCW_questions_tags_addTags( $questionId, $questionTags );
					}
					
					// Increment					
					$count_newQuestion++;
				}

				// Summary import.
				$page->showMessage( sprintf( __( 'Import complete! %d questions were imported, and %d questions could not be processed.', 'wp_courseware' ), $count_newQuestion, $count_aborted ) );


				// Show any skipped users
				if ( ! empty( $skippedList ) ) {
					printf( '<div id="wpcw_question_import_skipped">' );
						printf( '<b>' . __( 'The following %d questions were not imported:', 'wp_courseware' ) . '</b>', count( $skippedList ) );
						printf( '<table class="widefat">' );
							printf( '<thead>' );
								printf( '<tr>' );
									printf( '<th>%s</th>', __( 'Line #', 'wp_courseware' ) );
									printf( '<th>%s</th>', __( 'Reason why not imported', 'wp_courseware' ) );
									printf( '<th>%s</th>', __( 'Updated Anyway?', 'wp_courseware' ) );
								printf( '</tr>' );
							printf( '</thead>' );
							$odd = false;
							foreach ( $skippedList as $skipItem ) {
								printf( '<tr class="%s %s">', ( $odd ? 'alternate' : '' ), ( $skipItem[ 'aborted' ] ? 'wpcw_error' : 'wpcw_ok' ) );
								printf( '<td>%s</td>', $skipItem[ 'row_num' ] );
								printf( '<td>%s</td>', $skipItem[ 'reason' ] );
								printf( '<td>%s</td>', ( $skipItem[ 'aborted' ] ? __( 'No, Aborted', 'wp_courseware' ) : __( 'Yes', 'wp_courseware' ) ) );
								printf( '</tr>' );
								$odd = ! $odd;
							}
						printf( '</table>' );
					printf( '</div>' );
				} 

				// All done
				fclose($csvHandle);
			}
			else {
				$page->showMessage( __( 'Unfortunately, the temporary CSV file could not be opened for processing.', 'wp_courseware' ), true );
				return;
			}

		}
		// Error occured, so report a meaningful error
		else {
			switch ( $errornum ) {
				case UPLOAD_ERR_FORM_SIZE:
				case UPLOAD_ERR_INI_SIZE:
					$page->showMessage( __( "Unfortunately the file you've uploaded is too large for the system.", 'wp_courseware' ), true );
					break;

				case UPLOAD_ERR_PARTIAL:
				case UPLOAD_ERR_NO_FILE:
					$page->showMessage( __( "For some reason, the file you've uploaded didn't transfer correctly to the server. Please try again.", 'wp_courseware' ), true );
					break;

				case UPLOAD_ERR_NO_TMP_DIR:
				case UPLOAD_ERR_CANT_WRITE:
					$page->showMessage( __( "There appears to be an issue with your server, as the import file could not be stored in the temporary directory.", 'wp_courseware' ), true );
					break;

				case UPLOAD_ERR_EXTENSION:
					$page->showMessage( __( 'Unfortunately, you tried to upload a file that isn\'t a CSV file.', 'wp_courseware' ), true );
					break;
			}
		}
	} // end of if (isset($_FILES['import_questions_csv']['name']))
		unset($_POST);
}
}

function sixco_readexcel($file){
	require_once dirname(__FILE__) . '/PHPExcel-1.8/Classes/PHPExcel.php';	
	require_once(dirname(__FILE__) . '/PHPExcel-1.8/Classes/PHPExcel/Autoloader.php'); 
   $inputFile = $file['tmp_name'];
 	$inputFileN = move_uploaded_file($inputFile, substr($inputFile, 0, -3).'xlsx');
 	$inputFile = substr($inputFile, 0, -3).'xlsx';
 	$csvFile = substr($inputFile, 0, -4).'csv';
    $extension = strtoupper(pathinfo($inputFile, PATHINFO_EXTENSION)); 
    if($extension==""){$extension = 'XLSX';}
    if($extension == 'XLSX' || $extension == 'ODS'){

        //Read spreadsheeet workbook
        try {
             $inputFileType = PHPExcel_IOFactory::identify($inputFile);
             $objReader = PHPExcel_IOFactory::createReader($inputFileType);
                 $objPHPExcel = $objReader->load($inputFile);
        } catch(Exception $e) {
                die($e->getMessage());
        }

        //Get worksheet dimensions
        $sheet = $objPHPExcel->getSheet(0); 
        $highestRow = $sheet->getHighestRow(); 
        $highestColumn = $sheet->getHighestColumn();
		$fp =fopen($csvFile, 'w');
        //Loop through each row of the worksheet in turn
        for ($row = 1; $row <= $highestRow; $row++){ 
                //  Read a row of data into an array
                $rowData = $sheet->rangeToArray('A' . $row . ':' . $highestColumn . $row, NULL, TRUE, FALSE);
                //Insert into database 
                //echo "anu". ( (trim( $rowData[0][3])==TRUE )&& trim( $rowData[0][1])=="truefalse")."anu<br />"; 
                if( (trim( $rowData[0][3])==TRUE )&& trim( $rowData[0][1])=="truefalse"){
                	$rowData[0][3] = htmlentities2("TRUE");
                }
				else if(trim($rowData[0][1])=="truefalse" && (trim( $rowData[0][3])==FALSE )){
					$rowData[0][3] = htmlentities2("FALSE");
				}
				
			
                fputcsv($fp, $rowData[0]);
               // print_r($rowData);
              // echo  $rowData[0][3]."<br />";
			   //exit;
        }
        fclose($fp);
      // $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'CSV');
      // $objWriter->save($csvFile);
       unlink($inputFile);
       return $csvFile;
    }
    else{
       // echo "Please upload an XLSX or ODS file";
       return false;
    }
}
?>