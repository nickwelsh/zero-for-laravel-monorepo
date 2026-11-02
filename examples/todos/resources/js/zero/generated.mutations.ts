// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ClientContext, type ModelContract} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_dcb46793e910089df22156fc22391269": {
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
        "version": "dcb46793e910089df22156fc223912694443c5af58ada5cfb9d0b0854186a0b4",
        "returnType": "void"
    },
    "m_3db96884c449c1526ca129ad23a92d91": {
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
        "version": "3db96884c449c1526ca129ad23a92d919229df38e21592944cacf3fb2503afc7",
        "returnType": "void"
    },
    "m_56120a14c922034ed80ac4c6e8fbffbd": {
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
        "version": "56120a14c922034ed80ac4c6e8fbffbdc53be11319899f3f6b79a65c3553c0b3",
        "returnType": "array"
    },
    "m_62ec8cea799b02bd5ef6ab18d6ef1b4b": {
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
        "version": "62ec8cea799b02bd5ef6ab18d6ef1b4bb85e323857cdaed2336956cb2f039447",
        "returnType": "void"
    },
    "m_d130a045794ac87790e8e91a4cd02813": {
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
        "version": "d130a045794ac87790e8e91a4cd028130bc635cba7354d3d540ac0fad1fc04fc",
        "returnType": "void"
    },
    "m_2e5d81423675e0e2eaa7b0d20a7ab665": {
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
        "version": "2e5d81423675e0e2eaa7b0d20a7ab66548e4dc63b68dbe9c64002d5610f757b9",
        "returnType": "array"
    },
    "m_165bb13b1eca8466b1bacb24022c1381": {
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
        "version": "165bb13b1eca8466b1bacb24022c1381ed981367671b3f0e571918f02f7a3a97",
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
    "m_dcb46793e910089df22156fc22391269": {...codec0},
    "m_3db96884c449c1526ca129ad23a92d91": {...codec1},
    "m_56120a14c922034ed80ac4c6e8fbffbd": {...codec2},
    "m_62ec8cea799b02bd5ef6ab18d6ef1b4b": {...codec3},
    "m_d130a045794ac87790e8e91a4cd02813": {...codec4},
    "m_2e5d81423675e0e2eaa7b0d20a7ab665": {...codec5},
    "m_165bb13b1eca8466b1bacb24022c1381": {...codec6},
  };
  const runtime = createControllerMutations<S, C>(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "App\\Http\\Controllers\\ShareListController@__invoke": runtime.bind("m_dcb46793e910089df22156fc22391269", codecs["m_dcb46793e910089df22156fc22391269"]),
    "App\\Http\\Controllers\\TodoController@destroy": runtime.bind("m_3db96884c449c1526ca129ad23a92d91", codecs["m_3db96884c449c1526ca129ad23a92d91"]),
    "App\\Http\\Controllers\\TodoController@store": runtime.bind("m_56120a14c922034ed80ac4c6e8fbffbd", codecs["m_56120a14c922034ed80ac4c6e8fbffbd"]),
    "App\\Http\\Controllers\\TodoController@update": runtime.bind("m_62ec8cea799b02bd5ef6ab18d6ef1b4b", codecs["m_62ec8cea799b02bd5ef6ab18d6ef1b4b"]),
    "App\\Http\\Controllers\\TodoListController@destroy": runtime.bind("m_d130a045794ac87790e8e91a4cd02813", codecs["m_d130a045794ac87790e8e91a4cd02813"]),
    "App\\Http\\Controllers\\TodoListController@store": runtime.bind("m_2e5d81423675e0e2eaa7b0d20a7ab665", codecs["m_2e5d81423675e0e2eaa7b0d20a7ab665"]),
    "App\\Http\\Controllers\\TodoListController@update": runtime.bind("m_165bb13b1eca8466b1bacb24022c1381", codecs["m_165bb13b1eca8466b1bacb24022c1381"]),
  }};
}
