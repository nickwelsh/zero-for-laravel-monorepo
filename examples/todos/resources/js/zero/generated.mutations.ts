// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ModelContract} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_cc56b27d8db39835819de5f22876518d": {
        "action": "App\\Http\\Controllers\\ShareListController@__invoke",
        "optimism": "disabled",
        "conceal": true,
        "returns": false,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "App\\Http\\Requests\\ShareListRequest"
            },
            "list": {
                "kind": "model",
                "type": "App\\Models\\TodoList"
            },
            "mutation": {
                "kind": "context",
                "type": "ZeroForLaravel\\Mutation\\Mutation"
            }
        },
        "operations": [],
        "sites": {},
        "version": "cc56b27d8db39835819de5f22876518d741b320a5a61cdafc1d0cd99de0b7285",
        "returnType": "void"
    },
    "m_4bf80a90e9fbeb89a07eb4dbadb284aa": {
        "action": "App\\Http\\Controllers\\TodoController@destroy",
        "optimism": "generated",
        "conceal": true,
        "returns": false,
        "parameters": {
            "todo": {
                "kind": "model",
                "type": "App\\Models\\Todo"
            },
            "mutation": {
                "kind": "context",
                "type": "ZeroForLaravel\\Mutation\\Mutation"
            }
        },
        "operations": [
            {
                "op": "set",
                "name": "todo",
                "value": {
                    "op": "reload",
                    "record": {
                        "op": "local",
                        "name": "todo",
                        "result": {
                            "kind": "model",
                            "type": "App\\Models\\Todo"
                        }
                    },
                    "result": {
                        "kind": "model",
                        "type": "App\\Models\\Todo"
                    }
                }
            },
            {
                "op": "guard",
                "index": 0
            },
            {
                "op": "expression",
                "value": {
                    "op": "delete",
                    "record": {
                        "op": "local",
                        "name": "todo",
                        "result": {
                            "kind": "model",
                            "type": "App\\Models\\Todo"
                        }
                    },
                    "data": null
                }
            }
        ],
        "sites": {},
        "version": "4bf80a90e9fbeb89a07eb4dbadb284aa2198a6e8c53c11e990870bf8aad288a5",
        "returnType": "void"
    },
    "m_898bc883607f37899248c48fb28bd263": {
        "action": "App\\Http\\Controllers\\TodoController@store",
        "optimism": "generated",
        "conceal": false,
        "returns": true,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "App\\Http\\Requests\\SaveTodoRequest"
            }
        },
        "operations": [
            {
                "op": "guard",
                "index": 0
            },
            {
                "op": "set",
                "name": "todo",
                "value": {
                    "op": "create",
                    "model": "Todo",
                    "class": "App\\Models\\Todo",
                    "site": "s0",
                    "data": {
                        "op": "validated",
                        "base": {
                            "op": "local",
                            "name": "request",
                            "result": {
                                "kind": "request",
                                "type": "App\\Http\\Requests\\SaveTodoRequest"
                            }
                        },
                        "result": {
                            "kind": "input",
                            "type": "App\\Http\\Requests\\SaveTodoRequest",
                            "path": ""
                        }
                    },
                    "result": {
                        "kind": "model",
                        "type": "App\\Models\\Todo"
                    }
                }
            },
            {
                "op": "return",
                "value": {
                    "op": "object",
                    "items": [
                        {
                            "key": "id",
                            "value": {
                                "op": "get",
                                "base": {
                                    "op": "local",
                                    "name": "todo",
                                    "result": {
                                        "kind": "model",
                                        "type": "App\\Models\\Todo"
                                    }
                                },
                                "key": {
                                    "op": "literal",
                                    "value": "id"
                                },
                                "result": {
                                    "kind": "value",
                                    "type": "string",
                                    "nullable": false
                                }
                            }
                        }
                    ]
                }
            }
        ],
        "sites": {
            "s0": "uuid"
        },
        "version": "898bc883607f37899248c48fb28bd2637c98ec248f4ef5e27768813c5d240c74",
        "returnType": "array"
    },
    "m_8c191fc2ea748f190ec8d4156f063758": {
        "action": "App\\Http\\Controllers\\TodoController@update",
        "optimism": "generated",
        "conceal": true,
        "returns": false,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "App\\Http\\Requests\\SaveTodoRequest"
            },
            "todo": {
                "kind": "model",
                "type": "App\\Models\\Todo"
            },
            "mutation": {
                "kind": "context",
                "type": "ZeroForLaravel\\Mutation\\Mutation"
            }
        },
        "operations": [
            {
                "op": "set",
                "name": "todo",
                "value": {
                    "op": "reload",
                    "record": {
                        "op": "local",
                        "name": "todo",
                        "result": {
                            "kind": "model",
                            "type": "App\\Models\\Todo"
                        }
                    },
                    "result": {
                        "kind": "model",
                        "type": "App\\Models\\Todo"
                    }
                }
            },
            {
                "op": "guard",
                "index": 0
            },
            {
                "op": "expression",
                "value": {
                    "op": "update",
                    "record": {
                        "op": "local",
                        "name": "todo",
                        "result": {
                            "kind": "model",
                            "type": "App\\Models\\Todo"
                        }
                    },
                    "data": {
                        "op": "validated",
                        "base": {
                            "op": "local",
                            "name": "request",
                            "result": {
                                "kind": "request",
                                "type": "App\\Http\\Requests\\SaveTodoRequest"
                            }
                        },
                        "result": {
                            "kind": "input",
                            "type": "App\\Http\\Requests\\SaveTodoRequest",
                            "path": ""
                        }
                    }
                }
            }
        ],
        "sites": {},
        "version": "8c191fc2ea748f190ec8d4156f0637584bbade5aeed2912ddc0079b5fde2a98a",
        "returnType": "void"
    },
    "m_0aecc5591757aad0b1f84adb4179d0b9": {
        "action": "App\\Http\\Controllers\\TodoListController@destroy",
        "optimism": "generated",
        "conceal": true,
        "returns": false,
        "parameters": {
            "list": {
                "kind": "model",
                "type": "App\\Models\\TodoList"
            },
            "mutation": {
                "kind": "context",
                "type": "ZeroForLaravel\\Mutation\\Mutation"
            }
        },
        "operations": [
            {
                "op": "set",
                "name": "list",
                "value": {
                    "op": "reload",
                    "record": {
                        "op": "local",
                        "name": "list",
                        "result": {
                            "kind": "model",
                            "type": "App\\Models\\TodoList"
                        }
                    },
                    "result": {
                        "kind": "model",
                        "type": "App\\Models\\TodoList"
                    }
                }
            },
            {
                "op": "guard",
                "index": 0
            },
            {
                "op": "expression",
                "value": {
                    "op": "delete",
                    "record": {
                        "op": "local",
                        "name": "list",
                        "result": {
                            "kind": "model",
                            "type": "App\\Models\\TodoList"
                        }
                    },
                    "data": null
                }
            }
        ],
        "sites": {},
        "version": "0aecc5591757aad0b1f84adb4179d0b989b60d58981ebce1d5be0c48236a3de4",
        "returnType": "void"
    },
    "m_faba244a9ab11dcee9d07095e73fbc87": {
        "action": "App\\Http\\Controllers\\TodoListController@store",
        "optimism": "generated",
        "conceal": false,
        "returns": true,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "App\\Http\\Requests\\SaveTodoListRequest"
            }
        },
        "operations": [
            {
                "op": "guard",
                "index": 0
            },
            {
                "op": "set",
                "name": "list",
                "value": {
                    "op": "create",
                    "model": "TodoList",
                    "class": "App\\Models\\TodoList",
                    "site": "s0",
                    "data": {
                        "op": "validated",
                        "base": {
                            "op": "local",
                            "name": "request",
                            "result": {
                                "kind": "request",
                                "type": "App\\Http\\Requests\\SaveTodoListRequest"
                            }
                        },
                        "result": {
                            "kind": "input",
                            "type": "App\\Http\\Requests\\SaveTodoListRequest",
                            "path": ""
                        }
                    },
                    "result": {
                        "kind": "model",
                        "type": "App\\Models\\TodoList"
                    }
                }
            },
            {
                "op": "return",
                "value": {
                    "op": "object",
                    "items": [
                        {
                            "key": "id",
                            "value": {
                                "op": "get",
                                "base": {
                                    "op": "local",
                                    "name": "list",
                                    "result": {
                                        "kind": "model",
                                        "type": "App\\Models\\TodoList"
                                    }
                                },
                                "key": {
                                    "op": "literal",
                                    "value": "id"
                                },
                                "result": {
                                    "kind": "value",
                                    "type": "string",
                                    "nullable": false
                                }
                            }
                        }
                    ]
                }
            }
        ],
        "sites": {
            "s0": "uuid"
        },
        "version": "faba244a9ab11dcee9d07095e73fbc8738d0c04a9ba5fe2777d83a8000d85251",
        "returnType": "array"
    },
    "m_6020f1e50a2fc0b3593a4a1e578a4daa": {
        "action": "App\\Http\\Controllers\\TodoListController@update",
        "optimism": "generated",
        "conceal": true,
        "returns": false,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "App\\Http\\Requests\\SaveTodoListRequest"
            },
            "list": {
                "kind": "model",
                "type": "App\\Models\\TodoList"
            },
            "mutation": {
                "kind": "context",
                "type": "ZeroForLaravel\\Mutation\\Mutation"
            }
        },
        "operations": [
            {
                "op": "set",
                "name": "list",
                "value": {
                    "op": "reload",
                    "record": {
                        "op": "local",
                        "name": "list",
                        "result": {
                            "kind": "model",
                            "type": "App\\Models\\TodoList"
                        }
                    },
                    "result": {
                        "kind": "model",
                        "type": "App\\Models\\TodoList"
                    }
                }
            },
            {
                "op": "guard",
                "index": 0
            },
            {
                "op": "expression",
                "value": {
                    "op": "update",
                    "record": {
                        "op": "local",
                        "name": "list",
                        "result": {
                            "kind": "model",
                            "type": "App\\Models\\TodoList"
                        }
                    },
                    "data": {
                        "op": "validated",
                        "base": {
                            "op": "local",
                            "name": "request",
                            "result": {
                                "kind": "request",
                                "type": "App\\Http\\Requests\\SaveTodoListRequest"
                            }
                        },
                        "result": {
                            "kind": "input",
                            "type": "App\\Http\\Requests\\SaveTodoListRequest",
                            "path": ""
                        }
                    }
                }
            }
        ],
        "sites": {},
        "version": "6020f1e50a2fc0b3593a4a1e578a4daa09185645d806f5fe20c4c06f58d44516",
        "returnType": "void"
    }
} as const;
export type Input0 = {readonly "email": string};
export type ServerResult0 = undefined;
export type ClientResult0 = undefined;
export const inputSchema0 = z.strictObject({"email": z.string()});
export const serverResultSchema0 = z.undefined();
export const clientResultSchema0 = z.undefined();
const codec0 = {input: inputSchema0, encode: (input: Input0) => ({"email": input["email"]}), serverResult: serverResultSchema0, clientResult: clientResultSchema0};
export type Input1 = {};
export type ServerResult1 = undefined;
export type ClientResult1 = undefined;
export const inputSchema1 = z.strictObject({});
export const serverResultSchema1 = z.undefined();
export const clientResultSchema1 = z.undefined();
const codec1 = {input: inputSchema1, encode: (input: Input1) => ({}), serverResult: serverResultSchema1, clientResult: clientResultSchema1};
export type Input2 = {readonly "list_id"?: string | undefined; readonly "title"?: string | undefined; readonly "completed"?: boolean | undefined};
export type ServerResult2 = {readonly "id": string};
export type ClientResult2 = {readonly "id": string};
export const inputSchema2 = z.strictObject({"list_id": z.string().uuid().optional(), "title": z.string().refine(value => Array.from(value).length <= 200).optional(), "completed": z.boolean().optional()});
export const serverResultSchema2 = z.strictObject({"id": z.string()});
export const clientResultSchema2 = z.strictObject({"id": z.string()});
const codec2 = {input: inputSchema2, encode: (input: Input2) => ({...(input["list_id"] === undefined ? {} : {"list_id": input["list_id"]}), ...(input["title"] === undefined ? {} : {"title": input["title"]}), ...(input["completed"] === undefined ? {} : {"completed": input["completed"]})}), serverResult: serverResultSchema2, clientResult: clientResultSchema2};
export type Input3 = {readonly "list_id"?: string | undefined; readonly "title"?: string | undefined; readonly "completed"?: boolean | undefined};
export type ServerResult3 = undefined;
export type ClientResult3 = undefined;
export const inputSchema3 = z.strictObject({"list_id": z.string().uuid().optional(), "title": z.string().refine(value => Array.from(value).length <= 200).optional(), "completed": z.boolean().optional()});
export const serverResultSchema3 = z.undefined();
export const clientResultSchema3 = z.undefined();
const codec3 = {input: inputSchema3, encode: (input: Input3) => ({...(input["list_id"] === undefined ? {} : {"list_id": input["list_id"]}), ...(input["title"] === undefined ? {} : {"title": input["title"]}), ...(input["completed"] === undefined ? {} : {"completed": input["completed"]})}), serverResult: serverResultSchema3, clientResult: clientResultSchema3};
export type Input4 = {};
export type ServerResult4 = undefined;
export type ClientResult4 = undefined;
export const inputSchema4 = z.strictObject({});
export const serverResultSchema4 = z.undefined();
export const clientResultSchema4 = z.undefined();
const codec4 = {input: inputSchema4, encode: (input: Input4) => ({}), serverResult: serverResultSchema4, clientResult: clientResultSchema4};
export type Input5 = {readonly "name"?: string | undefined};
export type ServerResult5 = {readonly "id": string};
export type ClientResult5 = {readonly "id": string};
export const inputSchema5 = z.strictObject({"name": z.string().refine(value => Array.from(value).length <= 100).optional()});
export const serverResultSchema5 = z.strictObject({"id": z.string()});
export const clientResultSchema5 = z.strictObject({"id": z.string()});
const codec5 = {input: inputSchema5, encode: (input: Input5) => ({...(input["name"] === undefined ? {} : {"name": input["name"]})}), serverResult: serverResultSchema5, clientResult: clientResultSchema5};
export type Input6 = {readonly "name"?: string | undefined};
export type ServerResult6 = undefined;
export type ClientResult6 = undefined;
export const inputSchema6 = z.strictObject({"name": z.string().refine(value => Array.from(value).length <= 100).optional()});
export const serverResultSchema6 = z.undefined();
export const clientResultSchema6 = z.undefined();
const codec6 = {input: inputSchema6, encode: (input: Input6) => ({...(input["name"] === undefined ? {} : {"name": input["name"]})}), serverResult: serverResultSchema6, clientResult: clientResultSchema6};
export function createControllerMutationBindings<S extends Schema>(schema: S, models: Readonly<Record<string, ModelContract>>) {
  const codecs = {
    "m_cc56b27d8db39835819de5f22876518d": {...codec0},
    "m_4bf80a90e9fbeb89a07eb4dbadb284aa": {...codec1},
    "m_898bc883607f37899248c48fb28bd263": {...codec2},
    "m_8c191fc2ea748f190ec8d4156f063758": {...codec3},
    "m_0aecc5591757aad0b1f84adb4179d0b9": {...codec4},
    "m_faba244a9ab11dcee9d07095e73fbc87": {...codec5},
    "m_6020f1e50a2fc0b3593a4a1e578a4daa": {...codec6},
  };
  const runtime = createControllerMutations(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "App\\Http\\Controllers\\ShareListController@__invoke": runtime.bind("m_cc56b27d8db39835819de5f22876518d", codecs["m_cc56b27d8db39835819de5f22876518d"]),
    "App\\Http\\Controllers\\TodoController@destroy": runtime.bind("m_4bf80a90e9fbeb89a07eb4dbadb284aa", codecs["m_4bf80a90e9fbeb89a07eb4dbadb284aa"]),
    "App\\Http\\Controllers\\TodoController@store": runtime.bind("m_898bc883607f37899248c48fb28bd263", codecs["m_898bc883607f37899248c48fb28bd263"]),
    "App\\Http\\Controllers\\TodoController@update": runtime.bind("m_8c191fc2ea748f190ec8d4156f063758", codecs["m_8c191fc2ea748f190ec8d4156f063758"]),
    "App\\Http\\Controllers\\TodoListController@destroy": runtime.bind("m_0aecc5591757aad0b1f84adb4179d0b9", codecs["m_0aecc5591757aad0b1f84adb4179d0b9"]),
    "App\\Http\\Controllers\\TodoListController@store": runtime.bind("m_faba244a9ab11dcee9d07095e73fbc87", codecs["m_faba244a9ab11dcee9d07095e73fbc87"]),
    "App\\Http\\Controllers\\TodoListController@update": runtime.bind("m_6020f1e50a2fc0b3593a4a1e578a4daa", codecs["m_6020f1e50a2fc0b3593a4a1e578a4daa"]),
  }};
}
