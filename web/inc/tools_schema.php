<?php
/**
 * 工具 Schema 定义，供 Function Calling 使用。
 * 返回符合 OpenAI Function Calling 格式的工具列表。
 */

/**
 * 生成当前会话可用的工具列表。
 *
 * @param int $userId 用户 ID
 * @param int $convId 会话 ID
 * @param array $sshHosts 用户的 SSH 主机列表
 * @param int $currentHostId 当前项目绑定的主机 ID
 * @param array $currentProject 当前项目信息
 * @return array 工具列表
 */
function tools_schema(int $userId, int $convId, array $sshHosts, int $currentHostId, array $currentProject): array
{
    // 读取用户的工具开关配置
    $userTools = db_one(
        'SELECT tool_ssh_exec, tool_sftp_read, tool_sftp_write, tool_sftp_list, tool_sftp_delete, tool_sftp_patch,
                tool_file_list, tool_file_read, tool_file_write, tool_file_delete, tool_file_push, tool_file_patch,
                tool_ws_list, tool_ws_read, tool_ws_write, tool_ws_delete, tool_ws_patch, tool_ws_zip,
                tool_web_open, tool_web_search, tool_ppt_generate
           FROM users WHERE id = ? LIMIT 1',
        [$userId]
    );
    
    // 防御：查不到用户时全部默认开启
    if (!$userTools) {
        $userTools = [
            'tool_ssh_exec' => 1, 'tool_sftp_read' => 1, 'tool_sftp_write' => 1,
            'tool_sftp_list' => 1, 'tool_sftp_delete' => 1, 'tool_sftp_patch' => 1,
            'tool_file_list' => 1, 'tool_file_read' => 1, 'tool_file_write' => 1,
            'tool_file_delete' => 1, 'tool_file_push' => 1, 'tool_file_patch' => 1,
            'tool_ws_list' => 1, 'tool_ws_read' => 1, 'tool_ws_write' => 1, 'tool_ws_delete' => 1,
            'tool_ws_patch' => 1, 'tool_ws_zip' => 1,
            'tool_web_open' => 1, 'tool_web_search' => 1, 'tool_ppt_generate' => 1
        ];
    }
    
    $tools = [];
    
    // SSH 命令执行
    if (!empty($sshHosts) && (int)$userTools['tool_ssh_exec'] === 1) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'ssh_exec',
                'description' => '在远程服务器上执行 Shell 命令。适合安装软件、查看日志、重启服务等操作。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'host' => [
                            'type' => 'integer',
                            'description' => '服务器编号。当前项目默认服务器：' . $currentHostId
                        ],
                        'cmd' => [
                            'type' => 'string',
                            'description' => '要执行的 Shell 命令'
                        ]
                    ],
                    'required' => ['host', 'cmd']
                ]
            ]
        ];
    }
    
    // SFTP 文件操作
    if ($currentHostId > 0 && !empty($currentProject['deploy_dir'])) {
        if ((int)$userTools['tool_sftp_read'] === 1) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'sftp_read',
                    'description' => '读取服务器上的文件内容。适合查看配置文件、日志等。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => '相对于部署目录的文件路径，如 index.php 或 inc/config.php'
                            ]
                        ],
                        'required' => ['path']
                    ]
                ]
            ];
        }
        
        if ((int)$userTools['tool_sftp_write'] === 1) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'sftp_write',
                    'description' => '直接写入服务器上的文件。改动立即生效，写入前会自动备份。改动前会自动把原文件备份到远端 .kiro_backup/ 并在数据库里留一份，改坏了能一键还原。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => '相对于部署目录的文件路径'
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => '完整的文件内容'
                            ],
                            'note' => [
                                'type' => 'string',
                                'description' => '改动说明，一句话描述这次改了什么'
                            ]
                        ],
                        'required' => ['path', 'content']
                    ]
                ]
            ];
        }
        
        if ((int)$userTools['tool_sftp_patch'] === 1) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'sftp_patch',
                    'description' => '局部补丁修改服务器上的文件。适合大文件只改几行的场景，不必整文件重写。补丁按内容定位，不看行号。原片段必须在文件里唯一，出现多次会报错要求补上下文。写入前会自动备份，改坏了能还原。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => '相对于部署目录的文件路径'
                            ],
                            'original' => [
                                'type' => 'string',
                                'description' => '要被替换掉的原片段，照抄原文'
                            ],
                            'replacement' => [
                                'type' => 'string',
                                'description' => '替换后的新片段'
                            ],
                            'note' => [
                                'type' => 'string',
                                'description' => '一句话说明这次改了什么'
                            ]
                        ],
                        'required' => ['path', 'original', 'replacement']
                    ]
                ]
            ];
        }
        
        if ((int)$userTools['tool_sftp_list'] === 1) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'sftp_list',
                    'description' => '列出服务器上的目录内容。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'dir' => [
                                'type' => 'string',
                                'description' => '相对于部署目录的子目录路径，留空表示列出部署目录本身'
                            ]
                        ]
                    ]
                ]
            ];
        }
        
        if ((int)$userTools['tool_sftp_delete'] === 1) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'sftp_delete',
                    'description' => '删除服务器上的文件。删除前会自动备份，可还原。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => '相对于部署目录的文件路径'
                            ],
                            'note' => [
                                'type' => 'string',
                                'description' => '删除原因说明'
                            ]
                        ],
                        'required' => ['path']
                    ]
                ]
            ];
        }
    }
    
    // 本地仓操作
    if ($convId > 0) {
        if ((int)$userTools['tool_file_list'] === 1) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'file_list',
                    'description' => '列出本地仓的文件清单。可查看哪些文件已修改待推送。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'dirty' => [
                                'type' => 'integer',
                                'description' => '设为 1 只显示有改动的文件，设为 0 显示全部'
                            ]
                        ]
                    ]
                ]
            ];
        }
        
        if ((int)$userTools['tool_file_read'] === 1) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'file_read',
                    'description' => '读取本地仓中的文件内容。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => '仓内相对路径'
                            ]
                        ],
                        'required' => ['path']
                    ]
                ]
            ];
        }
        
        if ((int)$userTools['tool_file_write'] === 1) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'file_write',
                    'description' => '写入本地仓的文件。改动不会立即生效，需要执行 file_push 才能回传到服务器。本地副本有版本历史、能 diff、能一键回滚。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => '仓内相对路径'
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => '完整的文件内容'
                            ],
                            'note' => [
                                'type' => 'string',
                                'description' => '改动说明'
                            ]
                        ],
                        'required' => ['path', 'content']
                    ]
                ]
            ];
        }
        
        if ((int)$userTools['tool_file_patch'] === 1) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'file_patch',
                    'description' => '局部补丁修改本地仓中的文件。适合大文件只改几行的场景，不必整文件重写。补丁按内容定位，不看行号。原片段必须在文件里唯一，出现多次会报错要求补上下文。改动不会立即生效，需要 file_push 回传到服务器。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => '仓内相对路径'
                            ],
                            'original' => [
                                'type' => 'string',
                                'description' => '要被替换掉的原片段，照抄原文'
                            ],
                            'replacement' => [
                                'type' => 'string',
                                'description' => '替换后的新片段'
                            ],
                            'note' => [
                                'type' => 'string',
                                'description' => '改动说明'
                            ]
                        ],
                        'required' => ['path', 'original', 'replacement']
                    ]
                ]
            ];
        }
        
        if ((int)$userTools['tool_file_push'] === 1) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'file_push',
                    'description' => '将本地仓的改动推送到服务器。推送前会自动备份。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'note' => [
                                'type' => 'string',
                                'description' => '本次推送的说明'
                            ],
                            'confirm' => [
                                'type' => 'integer',
                                'description' => '如果推送清单中包含敏感文件且用户已确认，设为 1'
                            ]
                        ]
                    ]
                ]
            ];
        }
        
        if ((int)$userTools['tool_file_delete'] === 1) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'file_delete',
                    'description' => '删除本地仓中的文件。删除的是本地副本，该文件的历史版本也会一起清除，无法撤销。回传只上传改动过的文件、从不删远端，所以线上那份还在。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => [
                                'type' => 'string',
                                'description' => '仓内相对路径'
                            ],
                            'note' => [
                                'type' => 'string',
                                'description' => '删除原因说明'
                            ]
                        ],
                        'required' => ['path']
                    ]
                ]
            ];
        }
    }
    
    // 工作中心文件
    if ((int)$userTools['tool_ws_list'] === 1) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'ws_list',
                'description' => '列出工作中心的文件清单。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'q' => [
                            'type' => 'string',
                            'description' => '关键词过滤，留空表示列出全部'
                        ]
                    ]
                ]
            ]
        ];
    }
    
    if ((int)$userTools['tool_ws_read'] === 1) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'ws_read',
                'description' => '读取工作中心的文件内容。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => '文件名'
                        ]
                    ],
                    'required' => ['name']
                ]
            ]
        ];
    }
    
    if ((int)$userTools['tool_ws_write'] === 1) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'ws_write',
                'description' => '写入工作中心文件。用于保存生成的代码、文档等产出物。写进去的东西会一直保留，下次对话仍然在。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => '文件名，可包含目录如 docs/api.md'
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => '完整的文件内容'
                        ],
                        'note' => [
                            'type' => 'string',
                            'description' => '文件说明'
                        ]
                    ],
                    'required' => ['name', 'content']
                ]
            ]
        ];
    }
    
    if ((int)$userTools['tool_ws_patch'] === 1) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'ws_patch',
                'description' => '局部补丁修改工作中心的文件。适合大文件只改几行的场景，不必整文件重写。补丁按内容定位，原片段必须在文件里唯一。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => '文件名'
                        ],
                        'original' => [
                            'type' => 'string',
                            'description' => '要被替换掉的原片段，照抄原文'
                        ],
                        'replacement' => [
                            'type' => 'string',
                            'description' => '替换后的新片段'
                        ],
                        'note' => [
                            'type' => 'string',
                            'description' => '改动说明'
                        ]
                    ],
                    'required' => ['name', 'original', 'replacement']
                ]
            ]
        ];
    }
    
    if ((int)$userTools['tool_ws_delete'] === 1) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'ws_delete',
                'description' => '删除工作中心的文件。删除操作不可撤销。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => '文件名'
                        ],
                        'note' => [
                            'type' => 'string',
                            'description' => '删除原因说明'
                        ]
                    ],
                    'required' => ['name']
                ]
            ]
        ];
    }
    
    if ((int)$userTools['tool_ws_zip'] === 1) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'ws_zip',
                'description' => '把工作中心里的若干文件打成一个 zip 压缩包，同样存回工作中心。只能打包已有的文件。包内保留目录结构。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => '产物文件名，如 项目源码.zip'
                        ],
                        'files' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => '要打包的文件名列表，一行一个，照抄工作中心里的名字'
                        ],
                        'note' => [
                            'type' => 'string',
                            'description' => '一句话说明包里是什么'
                        ]
                    ],
                    'required' => ['name', 'files']
                ]
            ]
        ];
    }
    
    // 网页抓取
    if ((int)$userTools['tool_web_open'] === 1) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'web_open',
                'description' => '抓取网页内容。适合查看文档、获取最新信息等。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => '要抓取的网址'
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => '正文字数上限，默认 30000'
                        ]
                    ],
                    'required' => ['url']
                ]
            ]
        ];
    }
    
    // 实时搜索
    if ((int)$userTools['tool_web_search'] === 1) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'web_search',
                'description' => 'Look up current, real-world information — recent events and news, the latest version or release of something, who currently holds a role or office, prices, standings, or any fact that may have changed recently. The moment you are not fully certain of an up-to-date answer, call this tool — and call it as the VERY FIRST thing you output: emit no text whatsoever before the call. Do not write a preamble and do not state or explain a plan; never say things like "let me search", "I need to look that up", "I\'m not sure", or "I\'ll check" — any guidance about narrating a plan before calling a tool does not apply to this tool. Just emit the call. When the results come back, give the answer naturally, in the user\'s language, as if you had simply known it — never mention searching, looking anything up, or your knowledge being limited or outdated. Don\'t use this tool for coding, math, or well-established things you already know.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The search query.'
                        ]
                    ],
                    'required' => ['query']
                ]
            ]
        ];
    }
    
    // PPT 生成
    if ((int)$userTools['tool_ppt_generate'] === 1) {
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'ppt_generate',
                'description' => '生成 PowerPoint 演示文稿。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'description' => 'PPT 标题'
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'PPT 内容的 JSON 格式大纲'
                        ]
                    ],
                    'required' => ['content']
                ]
            ]
        ];
    }
    
    return $tools;
}
