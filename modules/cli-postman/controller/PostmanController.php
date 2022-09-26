<?php

/**
 * Postman controller
 * @package cli-postman
 * @version 0.0.3
 */

namespace CliPostman\Controller;

use Cli\Library\Bash;
use CliApp\Library\{
	Apps,
	Module,
	Config
};
use Mim\Library\Fs;

class PostmanController extends \CliApp\Controller
{
	private $postmanDir = [];

	public function generateAction()
	{
		$currentPath = getcwd();

		// make sure this is app base
		if (!$this->isAppBase($currentPath)) {
			Bash::error('Please run the command under exists application');
		}

		if(
			!is_file($currentPath . '/etc/cache/routes.php')
			|| !is_file($currentPath . '/etc/cache/config.php')
		) {
			Bash::error('No application cache found!');
		}
		$routes = include $currentPath . '/etc/cache/routes.php';
		$config = include $currentPath . '/etc/cache/config.php';

		$directories = new \DirectoryIterator($currentPath . '/modules');

		$controllerDocLookup = [];

		foreach ($directories as $fileinfo) {
			if ($fileinfo->isDir() && !$fileinfo->isDot()) {
				$dirName = $fileinfo->getFilename();
				$controllerPath = $currentPath . '/modules/' . $dirName . '/controller';
				if (is_dir($controllerPath)) {
					$controllerDir = new \DirectoryIterator($controllerPath);
					$tmpFile = '/tmp/postman.temp.php';
					foreach ($controllerDir as $controllerFile) {

						if ($controllerFile->isFile()) {

							$workingControllerFile = $controllerPath . '/' . $controllerFile->getFilename();
							$workingControllerFileContent = file_get_contents($workingControllerFile);

							$workingControllerFileContent = preg_replace('#\ extends\ .*#i', '', $workingControllerFileContent);
							Fs::write( $tmpFile, $workingControllerFileContent );
							include $tmpFile;
							$className = $this->getDeclaredClass($tmpFile);

							if (class_exists($this->getDeclaredClass($tmpFile))) {
								$declaredClass = $this->getDeclaredClass($tmpFile);
								$rc = new \ReflectionClass($declaredClass);
								if ($rcComment = $rc->getDocComment()) {

									preg_match("/^.*\@Doc.Path\b.*$/m", $rcComment, $docPathMatches);

									if (isset($docPathMatches[0])) {
										$docPath = $docPathMatches[0];
										$docPath = explode('@Doc.Path', $docPath);
										// postman document foldering found
										$docPath = str_replace('\\', '\/', trim(end($docPath)));
										$rcMethods = $rc->getMethods();
										foreach ($rcMethods as $method) {
											$rcMethodComment = $rc->getMethod($method->name)->getDocComment();

											$descLines = explode(PHP_EOL, $rcMethodComment);

											// find desc
											$description = '';
											foreach ($descLines as $index => $line) {
												preg_match("/^.*\@Doc\b.*$/m", $line, $containsDocDefiniton);
												if (count($containsDocDefiniton) > 0) {
													break;
												}
												if ($index > 0) {
													$description .= (($index > 1) ? PHP_EOL : '') . trim(str_replace(' *', '', $line));
												}
											}
											$currentMethodName = preg_split('/(?=[A-Z])/', $method->name);
											$currentMethodName = $currentMethodName[0];
											switch ($currentMethodName) {
												case 'index':
													$apiTitle = 'Get All';
													$description = empty($description) ? $apiTitle : $description;
													break;
												case 'single':
													$apiTitle = 'Get Single';
													$description = empty($description) ? $apiTitle : $description;
													break;
												case 'update':
													$apiTitle = 'Update Record';
													$description = empty($description) ? $apiTitle : $description;
													break;
												case 'create':
													$apiTitle = 'Create Record';
													$description = empty($description) ? $apiTitle : $description;
													break;
												case 'delete':
													$apiTitle = 'Delete Record';
													$description = empty($description) ? $apiTitle : $description;
													break;

												default:
													$apiTitle = $currentMethodName;
													$description = empty($description) ? $apiTitle : $description;
													break;
											}

											// look for Sorts
											$sorts = [];
											preg_match("/^.*\@Doc.Sorts\b.*$/m", $rcMethodComment, $sortFieldMatches);
											if (count($sortFieldMatches) > 0) {
												$sorts = explode('@Doc.Sorts', $sortFieldMatches[0]);
												$sorts = explode(',', trim($sorts[1] ?? ''));
											}
											// looks for filters
											$filters = [];
											preg_match("/^.*\@Doc.Filters\b.*$/m", $rcMethodComment, $filterFieldMatches);
											if (count($filterFieldMatches) > 0) {
												$filters = explode('@Doc.Filters', $filterFieldMatches[0]);
												$filters = explode(',', trim($filters[1] ?? ''));
											}
											// looks for form
											$form = '';
											preg_match("/^.*\@Doc.Form\b.*$/m", $rcMethodComment, $formFieldMatches);
											if (count($formFieldMatches) > 0) {
												$form = explode('@Doc.Form', $formFieldMatches[0]);
												$form = trim($form[1] ?? '');
											}

											$urlQuery = [];
											if( count($sorts) > 0 ) {

												$description .= "\n\nAllowed `sorts` field: `" . implode(',', $sorts) . "`";
												$urlQuery[] = [
													'key' => 'sorts',
													'value' => implode(',', $sorts),
													'disabled' => true,
												];
											}
											if( count($filters) > 0 ) {
												$description .= "\n\nAllowed `filters` field: `" . implode(',', $filters) . "`";
												$urlQuery[] = [
													'key' => 'filters',
													'value' => implode(',', $filters),
													'disabled' => true,
												];
											}
											// var_dump($rcMethodComment);
											$controllerDocLookup[] = [
												'controller' => $method->class,
												'method' => $currentMethodName,
												'handler_id' => sprintf('%s::%s', preg_replace('/Controller$/', '', $method->class), $currentMethodName),
												'api_folder' => explode('/', $docPath),
												'api_title' => $apiTitle,
												'description' => "## " . $description,
												'sorts' => implode(',', $sorts),
												'filters' => implode(',', $filters),
												'query' => (count($urlQuery) > 0) ? $urlQuery : null,
												'form' => $form,
												'description_table' => ''
											];
										}
									}
								}
							}
						}
					}
					unlink($tmpFile);
				}
			}
		}

		$matches = [];
		foreach ($routes->api as $index => $apiRoute) {
			foreach ($controllerDocLookup as &$apiDocCandidate) {
				if ($apiRoute->handler === $apiDocCandidate['handler_id']) {
					$apiDocCandidate['url'] = $apiRoute->path->value;
					$apiDocCandidate['method'] = $apiRoute->method;
					$rawBody = "";
					$descriptionTable = "";

					if (isset($config->libForm->forms->{$apiDocCandidate['form']})) {
						$descriptionTable .= "name | default | description \n";
						$descriptionTable .= "--- | --- | --- \n";

						foreach ($config->libForm->forms->{$apiDocCandidate['form']} as $k => $param) {
							$rawBody .= "\t\"" . $k . "\":\"\",";
							$descriptionTable .= "$k | none | $k \n";
						}
						$rawBody = rtrim($rawBody, ',');
						$rawBody = implode(",\n", explode(',', $rawBody));
					}
					if ($apiDocCandidate['method'] === 'POST' || $apiDocCandidate['method'] === 'PUT') {
						$rawBody = "{\n" . $rawBody . "\n}";
					}
					$apiDocCandidate['raw_body'] = $rawBody;
					$apiDocCandidate['description_table'] = $descriptionTable;

					$paths = preg_replace('/(\(:).*?(\))/', '{{}}', $apiRoute->path->value);
					$paths = explode('/', $paths);
					foreach ($paths as $key => &$path) {
						if ($path === '{{}}') {
							$path = '{{' . strtoupper(($paths[$key - 1] ?? '') . '_id') . '}}';
						}
					}
					$apiDocCandidate['path'] = implode('/', $paths);
					$matches[] = $apiDocCandidate;
					unset($paths);
				}
			}
		}
		unset($apiDocCandidate);
		



		// $paths = [];
		// foreach ($matches as $match) {
		// 	$tmpArr = $match['api_folder'];
		// 	$arr = [];
		// 	$ref = &$arr;
		// 	foreach ($tmpArr as $index => $key) {
		// 		$ref[$key] = [];
		// 		if (count($tmpArr) === ($index + 1)) {
		// 			$ref = &$ref[$key];
		// 		} else {
		// 			$ref = &$ref[$key];
		// 		}
		// 		if (count($tmpArr) === $index + 1) {
		// 			$this->walkAndAssign($arr, $match);
		// 			$ref = &$ref[$key];
		// 		}
		// 	}
		// 	$ref = [];
		// 	$paths = array_merge_recursive($paths, $arr);
		// }

		$paths = [];
		foreach ($matches as $match) {
			$tmpArr = $match['api_folder'];
			$arr = [];
			$ref = &$arr;
			foreach ($tmpArr as $index => $key) {
				$ref[$key] = [];
				if (count($tmpArr) === ($index + 1)) {
					$ref = &$ref[$key];
				} else {
					$ref = &$ref[$key];
				}
			}
			$ref = [];
			$paths = array_merge_recursive($paths, $arr);
		}

		$this->recursiveSetArrayForm($paths);
		$this->recursiveArrayValues($paths);
		$paths = array_values($paths);
		foreach ($matches as $key => $match) {
			$this->recursiveAssignDocument($match, $paths);
		}

		$name = preg_replace('/\s+/', '-',$config->name );
		$postmanCollection = [
			'variables' => [],
			'info'      => [
				'name'        => ucwords( str_replace('-', ' ', $name) ),
				'_postman_id' => $this->uuidv4(),
				'description' => '',
				'schema'      => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
			],
			'item' => $paths
		];
		
		$fullpath = $currentPath . '/' . $name . '.json';
		


		Fs::write( $fullpath, json_encode($postmanCollection));
		Bash::echo( 'Postmen document created : ' . $fullpath );
	}

	private function recursiveAssignDocument($document, &$collections)
	{
		//loop collections
		foreach ($collections as $key => &$collection) {
			if (!is_array($collection)) {
				return;
			}
			// check for item's child
			if (!isset($collection['name'])) {
				$this->recursiveAssignDocument($document, $collection);
			}
			// check if collection name registered in document's api_folder
			elseif (in_array($collection['name'], $document['api_folder'])) {
				$this->recursiveAssignDocument($document, $collection);
				// check if collection has item
				if (isset($collection['item'])) {
					$this->recursiveAssignDocument($document, $collection['item']);
				}
			}
			// if( end($document['api_folder']) === $collection['name'] ) {
			// 	$collection['item'][] = [
			// 		'name'     => $document['api_title'],
			// 		"event" => [
			// 			[
			// 				"listen" => "test",
			// 				"script" => [
			// 					"exec" => [
			// 						"const responseJson = pm.response.json();",
			// 						"pm.expect(responseJson.error).to.eql(0);"
			// 					],
			// 					"type" => "text/javascript"
			// 				]
			// 			]
			// 		],
			// 		'request'  => [
			// 			'auth'        => '',
			// 			'method'      => strtoupper($document['method']),
			// 			'header'      => [
			// 				[
			// 					'key'         => 'Accept',
			// 					'value'       => 'application/json',
			// 					'type'		  => 'text',
			// 					'description' => null,
			// 				],
			// 				[
			// 					'key'         => 'Content-Type',
			// 					'value'       => 'application/json',
			// 					'type'		  => 'text',
			// 					'description' => null,
			// 				],
			// 				[
			// 					'key'         => 'Authorization',
			// 					'value'       => '{{ACCESS_TOKEN}}',
			// 					'type'		  => 'text',
			// 					'description' => null,
			// 				],
			// 			],
			// 			'body'        => [
			// 				'mode' => 'raw',
			// 				'raw'  => $document['raw_body'],
			// 			],
			// 			'url'         => [
			// 				'raw'   => '{{HOST}}' . $document['path'],
			// 				'host'  => '{{HOST}}' . $document['path'],
			// 				'variable' => null,
			// 				'query' => $document['query'],
			// 			],
			// 			'description' => $document['description'] . "\n\n" . $document['description_table'],
			// 		],
			// 		'response' => [],
			// 	];
			// }
			// if collection name and document api_folder match, write document
			if (isset($collection['name']) && $collection['name'] === end($document['api_folder'])) {
				$collection['item'][] = [
					'name'     => $document['api_title'],
					"event" => [
						[
							"listen" => "test",
							"script" => [
								"exec" => [
									"const responseJson = pm.response.json();",
									"pm.expect(responseJson.error).to.eql(0);"
								],
								"type" => "text/javascript"
							]
						]
					],
					'request'  => [
						'auth'        => '',
						'method'      => strtoupper($document['method']),
						'header'      => [
							[
								'key'         => 'Accept',
								'value'       => 'application/json',
								'type'		  => 'text',
								'description' => null,
							],
							[
								'key'         => 'Content-Type',
								'value'       => 'application/json',
								'type'		  => 'text',
								'description' => null,
							],
							[
								'key'         => 'Authorization',
								'value'       => '{{ACCESS_TOKEN}}',
								'type'		  => 'text',
								'description' => null,
							],
						],
						'body'        => [
							'mode' => 'raw',
							'raw'  => $document['raw_body'],
						],
						'url'         => [
							'raw'   => '{{HOST}}' . $document['path'],
							'host'  => '{{HOST}}' . $document['path'],
							'variable' => null,
							'query' => $document['query'],
						],
						'description' => $document['description'] . "\n\n" . $document['description_table'],
					],
					'response' => [],
				];
			}
		}
	}

	private function recursiveSetArrayForm(&$array)
	{
		foreach ($array as $key => &$r) {
			if (is_array($r)) {
				$this->recursiveSetArrayForm($r);
			}
			// declare postman array form
			$r = [
				'name' => $key,
				'item' => $r
			];
		}
	}

	private function recursiveArrayValues(&$array)
	{
		if (!is_array($array)) {
			return;
		}
		// loop collection array
		foreach ($array as $key => &$r) {
			if (is_array($r)) {
				// check if item's child
				if (is_int($key)) {
					$r['item'] = array_values($r['item']);
					$this->recursiveArrayValues($r);
				}
				// check if collection has item, then extract them
				elseif (isset($r['item'])) {
					$r['item'] = array_values($r['item']);
					$this->recursiveArrayValues($r['item']);
				}
				// extract ?, maybe not usable
				else {
					$r = array_values($r);
				}
			}
		}
	}

	private function uuidv4($data = null): string
	{
		$data = $data ?? random_bytes(16);
		assert(strlen($data) == 16);

		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	private function getDeclaredClass($address)
	{
		$classes   = [];
		$namespace = '';
		$tokens    = \PhpToken::tokenize(file_get_contents($address));

		for ($i = 0; $i < count($tokens); $i++) {
			if ($tokens[$i]->getTokenName() === 'T_NAMESPACE') {
				for ($j = $i + 1; $j < count($tokens); $j++) {
					if ($tokens[$j]->getTokenName() === 'T_NAME_QUALIFIED') {
						$namespace = $tokens[$j]->text;
						break;
					}
				}
			}

			if ($tokens[$i]->getTokenName() === 'T_CLASS') {
				for ($j = $i + 1; $j < count($tokens); $j++) {
					if ($tokens[$j]->getTokenName() === 'T_WHITESPACE') {
						continue;
					}

					if ($tokens[$j]->getTokenName() === 'T_STRING') {
						$classes[] = $namespace . '\\' . $tokens[$j]->text;
						// just skip if we just found one
						break;
					} else {
						break;
					}
				}
			}
		}
		return $classes[0] ?? null;
	}
}
