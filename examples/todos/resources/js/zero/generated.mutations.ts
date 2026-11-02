// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ClientContext, type ModelContract} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_8b3ab4da8183badf52abea2ad68fca78": {
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
        "version": "8b3ab4da8183badf52abea2ad68fca7815383e12064d179a5c080401912301c7",
        "returnType": "void"
    },
    "m_acb842ab9a7e453ee66442d844a5d4ba": {
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
        "version": "acb842ab9a7e453ee66442d844a5d4ba1cbd932cc8b2f2207a0e4c2e45401dbc",
        "returnType": "void"
    },
    "m_4682cb01498cda3c3f3982b27e899e24": {
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
        "version": "4682cb01498cda3c3f3982b27e899e240c69be49c28cc16b7ed7950d2c2ef12a",
        "returnType": "array"
    },
    "m_b1d04dfc0e21e6dd42e69dc7ecbd0208": {
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
        "version": "b1d04dfc0e21e6dd42e69dc7ecbd020896d878d54ff869a2ade97e009c2b882b",
        "returnType": "void"
    },
    "m_60e6d901d92ec41b3ff260eb5e534f2b": {
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
        "version": "60e6d901d92ec41b3ff260eb5e534f2baf3b9149a85745b9776e2a42012e3d8b",
        "returnType": "void"
    },
    "m_7e902a5a03c439d9c9537cfbfefd4847": {
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
        "version": "7e902a5a03c439d9c9537cfbfefd48479296a97e1135f42f7765aa91bfebbf1a",
        "returnType": "array"
    },
    "m_d1b362479840ebab00de93d623a3673d": {
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
        "version": "d1b362479840ebab00de93d623a3673d34a6e719cbb1332a99f4a693da4b1ea1",
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
export function createControllerMutationBindings<S extends Schema, C extends ClientContext = ClientContext>(schema: S, models: Readonly<Record<string, ModelContract>>) {
  const codecs = {
    "m_8b3ab4da8183badf52abea2ad68fca78": {...codec0},
    "m_acb842ab9a7e453ee66442d844a5d4ba": {...codec1},
    "m_4682cb01498cda3c3f3982b27e899e24": {...codec2},
    "m_b1d04dfc0e21e6dd42e69dc7ecbd0208": {...codec3},
    "m_60e6d901d92ec41b3ff260eb5e534f2b": {...codec4},
    "m_7e902a5a03c439d9c9537cfbfefd4847": {...codec5},
    "m_d1b362479840ebab00de93d623a3673d": {...codec6},
  };
  const runtime = createControllerMutations<S, C>(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "App\\Http\\Controllers\\ShareListController@__invoke": runtime.bind("m_8b3ab4da8183badf52abea2ad68fca78", codecs["m_8b3ab4da8183badf52abea2ad68fca78"]),
    "App\\Http\\Controllers\\TodoController@destroy": runtime.bind("m_acb842ab9a7e453ee66442d844a5d4ba", codecs["m_acb842ab9a7e453ee66442d844a5d4ba"]),
    "App\\Http\\Controllers\\TodoController@store": runtime.bind("m_4682cb01498cda3c3f3982b27e899e24", codecs["m_4682cb01498cda3c3f3982b27e899e24"]),
    "App\\Http\\Controllers\\TodoController@update": runtime.bind("m_b1d04dfc0e21e6dd42e69dc7ecbd0208", codecs["m_b1d04dfc0e21e6dd42e69dc7ecbd0208"]),
    "App\\Http\\Controllers\\TodoListController@destroy": runtime.bind("m_60e6d901d92ec41b3ff260eb5e534f2b", codecs["m_60e6d901d92ec41b3ff260eb5e534f2b"]),
    "App\\Http\\Controllers\\TodoListController@store": runtime.bind("m_7e902a5a03c439d9c9537cfbfefd4847", codecs["m_7e902a5a03c439d9c9537cfbfefd4847"]),
    "App\\Http\\Controllers\\TodoListController@update": runtime.bind("m_d1b362479840ebab00de93d623a3673d", codecs["m_d1b362479840ebab00de93d623a3673d"]),
  }};
}
