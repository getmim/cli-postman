<?php

/**
 * Postman controller
 * @package cli-postman
 * @version 0.0.1
 */

namespace CliPostman\Controller;

use Cli\Library\Bash;
use CliApp\Library\{
	Apps,
	Module,
	Config
};

class PostmanController extends \Cli\Controller
{
	public function compressAction(): void
	{
		$apps = Apps::getAll();
		$currentPath = getcwd();
		$routes = include $currentPath . '/etc/cache/routes.php';
		$config = include $currentPath . '/etc/cache/config.php';

		$collect = [
			'variables' => [],
			'info'      => [
				'name'        => $config->name,
				'_postman_id' => $this->uuidv4(),
				'description' => '',
				'schema'      => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
			],
		];

		foreach ($routes->api as $index => $route) {
			$name = explode('\\', $route->handler);
			$name = explode('::', array_pop($name));
			$folder = ucwords(implode(' ', preg_split('/(?=[A-Z])/', ($name[0] ?? $route->path->value))));
			$form = 'api.bb' . strtolower(implode('-', preg_split('/(?=[A-Z])/', ($name[0] ?? $route->path->value)))) . '.' . $name[1];
			$name = ($name[0] ?? $route->path->value) . ' ' . ($name[1] ?? '-');
			$name = ucwords(implode(' ', preg_split('/(?=[A-Z])/', $name)));

			$rawBody = "";
			if (isset($config->libForm->forms->{$form})) {
				foreach ($config->libForm->forms->{$form} as $k => $param) {
					$rawBody .= "\"" . $k . "\":\"\",";
				}
				$rawBody = rtrim($rawBody, ',');
				$rawBody = implode(",\n", explode(',', $rawBody));
			}

			$path = preg_replace(['~[{[(:]~', '~[)}>]~'], ['{', '}}'], $route->path->value);
			$collect[] = [
				'path' =>  $path,
				'header' => [
					'Accept' => 'application/json',
					'Content-Type' => 'application/json',
					'Authorization' => '{{ACCESS_TOKEN}}'
				],
				'body' => []
			];

			$collect['item'][$folder][] =  [
				'name'     => $name,
				'request'  => [
					'auth'        => '',
					'method'      => strtoupper($route->_method[0]),
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
						'raw'  => "{\n$rawBody\n}",
					],
					'url'         => [
						'raw'   => '{{HOST}}' . $path,
						'host'  => '{{HOST}}' . $path,
						'variable' => null,
						'query' => null,
					],
					'description' => null,
				],
				'response' => [],
			];
		}
		foreach ($collect['item'] as $k => &$clone) {
			$clone = [
				'name' => $k,
				'item' => $clone
			];
		}
		$collect['item'] = array_values(array_values($collect['item']));


		file_put_contents($config->name . '.json', json_encode($collect));

		echo "Postman Collection Generated";
		return;
	}

	private function uuidv4($data = null): string
	{
		$data = $data ?? random_bytes(16);
		assert(strlen($data) == 16);

		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
