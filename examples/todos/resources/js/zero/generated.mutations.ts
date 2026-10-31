// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ModelContract} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_80aaa0bb59e6ad59856c2fdb450891b4": {
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
        "version": "80aaa0bb59e6ad59856c2fdb450891b445ae056d4da6e3826190584da2f0b1c5",
        "returnType": "void"
    },
    "m_0bcf40bb888705f85b344867b89aab04": {
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
        "version": "0bcf40bb888705f85b344867b89aab04b5d26caabc57d1e0d38c00985a2a74ba",
        "returnType": "void"
    },
    "m_b0c58c177425ca9dc172decf05a24d81": {
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
        "version": "b0c58c177425ca9dc172decf05a24d81f4a287336b4bd55089ba1a886327ccec",
        "returnType": "array"
    },
    "m_346d8c43e547e41a57fc168ff963a72f": {
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
        "version": "346d8c43e547e41a57fc168ff963a72f7c3718a9a98353349dc0f8a964aba161",
        "returnType": "void"
    },
    "m_9d7195c6688b9e5f1503612e03b379f9": {
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
        "version": "9d7195c6688b9e5f1503612e03b379f94ff64fc40b4a7d1fc49674dae3337318",
        "returnType": "void"
    },
    "m_ebb170b8ab48673b0b9ed95701ff999a": {
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
        "version": "ebb170b8ab48673b0b9ed95701ff999ae7dbdc86107b2e3738c2bfc396abbae1",
        "returnType": "array"
    },
    "m_9885930d6180e37ae8488db1abfeebaa": {
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
        "version": "9885930d6180e37ae8488db1abfeebaa79b55b912868c4cf7e0c230eb5d40fba",
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
    "m_80aaa0bb59e6ad59856c2fdb450891b4": {...codec0},
    "m_0bcf40bb888705f85b344867b89aab04": {...codec1},
    "m_b0c58c177425ca9dc172decf05a24d81": {...codec2},
    "m_346d8c43e547e41a57fc168ff963a72f": {...codec3},
    "m_9d7195c6688b9e5f1503612e03b379f9": {...codec4},
    "m_ebb170b8ab48673b0b9ed95701ff999a": {...codec5},
    "m_9885930d6180e37ae8488db1abfeebaa": {...codec6},
  };
  const runtime = createControllerMutations(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "App\\Http\\Controllers\\ShareListController@__invoke": runtime.bind("m_80aaa0bb59e6ad59856c2fdb450891b4", codecs["m_80aaa0bb59e6ad59856c2fdb450891b4"]),
    "App\\Http\\Controllers\\TodoController@destroy": runtime.bind("m_0bcf40bb888705f85b344867b89aab04", codecs["m_0bcf40bb888705f85b344867b89aab04"]),
    "App\\Http\\Controllers\\TodoController@store": runtime.bind("m_b0c58c177425ca9dc172decf05a24d81", codecs["m_b0c58c177425ca9dc172decf05a24d81"]),
    "App\\Http\\Controllers\\TodoController@update": runtime.bind("m_346d8c43e547e41a57fc168ff963a72f", codecs["m_346d8c43e547e41a57fc168ff963a72f"]),
    "App\\Http\\Controllers\\TodoListController@destroy": runtime.bind("m_9d7195c6688b9e5f1503612e03b379f9", codecs["m_9d7195c6688b9e5f1503612e03b379f9"]),
    "App\\Http\\Controllers\\TodoListController@store": runtime.bind("m_ebb170b8ab48673b0b9ed95701ff999a", codecs["m_ebb170b8ab48673b0b9ed95701ff999a"]),
    "App\\Http\\Controllers\\TodoListController@update": runtime.bind("m_9885930d6180e37ae8488db1abfeebaa", codecs["m_9885930d6180e37ae8488db1abfeebaa"]),
  }};
}
