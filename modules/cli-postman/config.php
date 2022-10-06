<?php
/**
 * CLI Postman
 * @package cli-postman
 * @version 0.2.2
 */

return [
    '__name' => 'cli-postman',
    '__version' => '0.2.1',
    '__git' => 'git@github.com:godamri/cli-postman.git',
    '__license' => 'MIT',
    '__author' => [
        'name' => 'Rian',
        'email' => 'godamri@gmail.com',
        'website' => 'https://--.com/'
    ],
    '__files' => [
        'modules/cli-postman' => ['install', 'update', 'remove']
    ],
    '__dependencies' => [
        'required' => [
            [
                'cli' => null
            ]
        ],
        'optional' => []
    ],
    'autoload' => [
        'classes' => [
            'CliPostman\\Controller' => [
                'type' => 'file',
                'base' => 'modules/cli-postman/controller'
            ],
            'CliPostman\\Library' => [
                'type' => 'file',
                'base' => 'modules/cli-postman/library'
            ]
        ]
    ],
    'routes' => [
        'tool-app' => [
            'toolPostmanCollection' => [
                'info' => 'Generate postman collection',
                'path' => [
                    'value' => 'postman',
                ],
                'handler' => 'CliPostman\\Controller\\Postman::generate'
            ]
        ]
    ],
    
    'cli' => [
    	'autocomplete' => [
    		'!^postman' => [
    			'priority' => 7,
    			'handler' => [
                    'class' => 'CliPostman\\Library\\Autocomplete',
                    'method' => 'files'
                ]
    		],

    		'!^postman( [a-z]*)?$!' => [
    			'priority' => 6,
    			'handler' => [
                    'class' => 'CliPostman\\Library\\Autocomplete',
                    'method' => 'command'
                ]
    		]
    	]
    ]
];
