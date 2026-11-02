// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ClientContext, type ModelContract, type ControllerMutationOptions} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_db13b3ecab1502b8b4572340a371a384": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@store",
        "optimism": "generated",
        "conceal": false,
        "returns": true,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest"
            }
        },
        "operations": [
            {
                "op": "set",
                "name": "data",
                "value": {
                    "op": "validated",
                    "base": {
                        "op": "local",
                        "name": "request",
                        "result": {
                            "kind": "request",
                            "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest"
                        }
                    },
                    "result": {
                        "kind": "input",
                        "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest",
                        "path": ""
                    }
                }
            },
            {
                "op": "set",
                "name": "task",
                "value": {
                    "op": "create",
                    "model": "Task",
                    "class": "ZeroForLaravel\\Tests\\Fixtures\\Task",
                    "site": "s0",
                    "data": {
                        "op": "local",
                        "name": "data",
                        "result": {
                            "kind": "input",
                            "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest",
                            "path": ""
                        }
                    },
                    "result": {
                        "kind": "model",
                        "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
                    }
                }
            },
            {
                "op": "if",
                "condition": {
                    "op": "binary",
                    "operator": "&&",
                    "left": {
                        "op": "isset",
                        "value": {
                            "op": "get",
                            "base": {
                                "op": "local",
                                "name": "data",
                                "result": {
                                    "kind": "input",
                                    "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest",
                                    "path": ""
                                }
                            },
                            "key": {
                                "op": "literal",
                                "value": "done"
                            },
                            "result": {
                                "kind": "input",
                                "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest",
                                "path": "done"
                            }
                        }
                    },
                    "right": {
                        "op": "binary",
                        "operator": "===",
                        "left": {
                            "op": "get",
                            "base": {
                                "op": "local",
                                "name": "data",
                                "result": {
                                    "kind": "input",
                                    "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest",
                                    "path": ""
                                }
                            },
                            "key": {
                                "op": "literal",
                                "value": "done"
                            },
                            "result": {
                                "kind": "input",
                                "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest",
                                "path": "done"
                            }
                        },
                        "right": {
                            "op": "literal",
                            "value": true
                        }
                    }
                },
                "then": [
                    {
                        "op": "expression",
                        "value": {
                            "op": "create",
                            "model": "Task",
                            "class": "ZeroForLaravel\\Tests\\Fixtures\\Task",
                            "site": "s1",
                            "data": {
                                "op": "object",
                                "items": [
                                    {
                                        "key": "title",
                                        "value": {
                                            "op": "get",
                                            "base": {
                                                "op": "local",
                                                "name": "task",
                                                "result": {
                                                    "kind": "model",
                                                    "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
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
                            },
                            "result": {
                                "kind": "model",
                                "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
                            }
                        }
                    }
                ],
                "else": []
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
                                    "name": "task",
                                    "result": {
                                        "kind": "model",
                                        "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
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
            "s0": "uuid",
            "s1": "uuid"
        },
        "version": "db13b3ecab1502b8b4572340a371a384ab45fd4a2250fc9f8bb221bef34b5d24",
        "returnType": "array"
    },
    "m_5de6ec75db4b013bb4cc031cdedf8c0a": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@complete",
        "optimism": "generated",
        "conceal": false,
        "returns": true,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest"
            },
            "task": {
                "kind": "model",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
            },
            "mutation": {
                "kind": "context",
                "type": "ZeroForLaravel\\Mutation\\Mutation"
            },
            "helper": {
                "kind": "helper",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationHelper"
            }
        },
        "operations": [
            {
                "op": "set",
                "name": "task",
                "value": {
                    "op": "reload",
                    "record": {
                        "op": "local",
                        "name": "task",
                        "result": {
                            "kind": "model",
                            "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
                        }
                    },
                    "result": {
                        "kind": "model",
                        "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
                    }
                }
            },
            {
                "op": "guard",
                "index": 0
            },
            {
                "op": "set",
                "name": "before",
                "value": {
                    "op": "get",
                    "base": {
                        "op": "local",
                        "name": "task",
                        "result": {
                            "kind": "model",
                            "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
                        }
                    },
                    "key": {
                        "op": "literal",
                        "value": "done"
                    },
                    "result": {
                        "kind": "value",
                        "type": "boolean",
                        "nullable": false
                    }
                }
            },
            {
                "op": "expression",
                "value": {
                    "op": "call",
                    "parameters": [
                        "mutation",
                        "task"
                    ],
                    "args": [
                        {
                            "op": "local",
                            "name": "mutation",
                            "result": {
                                "kind": "context",
                                "type": "ZeroForLaravel\\Mutation\\Mutation"
                            }
                        },
                        {
                            "op": "local",
                            "name": "task",
                            "result": {
                                "kind": "model",
                                "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
                            }
                        }
                    ],
                    "body": [
                        {
                            "op": "expression",
                            "value": {
                                "op": "update",
                                "record": {
                                    "op": "local",
                                    "name": "task",
                                    "result": {
                                        "kind": "model",
                                        "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
                                    }
                                },
                                "data": {
                                    "op": "object",
                                    "items": [
                                        {
                                            "key": "done",
                                            "value": {
                                                "op": "literal",
                                                "value": true
                                            }
                                        }
                                    ]
                                }
                            }
                        }
                    ]
                }
            },
            {
                "op": "return",
                "value": {
                    "op": "object",
                    "items": [
                        {
                            "key": "before",
                            "value": {
                                "op": "local",
                                "name": "before",
                                "result": {
                                    "kind": "value",
                                    "type": "boolean",
                                    "nullable": false
                                }
                            }
                        },
                        {
                            "key": "after",
                            "value": {
                                "op": "get",
                                "base": {
                                    "op": "local",
                                    "name": "task",
                                    "result": {
                                        "kind": "model",
                                        "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
                                    }
                                },
                                "key": {
                                    "op": "literal",
                                    "value": "done"
                                },
                                "result": {
                                    "kind": "value",
                                    "type": "boolean",
                                    "nullable": false
                                }
                            }
                        }
                    ]
                }
            }
        ],
        "sites": {},
        "version": "5de6ec75db4b013bb4cc031cdedf8c0a203018f413d9baba0705e2078fba1fef",
        "returnType": "array"
    },
    "m_c22851fa301a9e5a235ce892065130e5": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@authoritative",
        "optimism": "disabled",
        "conceal": false,
        "returns": true,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest"
            }
        },
        "operations": [],
        "sites": {},
        "version": "c22851fa301a9e5a235ce892065130e5a08902ba14d7704b3063566c3c6bd664",
        "returnType": "null"
    },
    "m_8898cfb23f3791cade785cb0e0634397": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@noResult",
        "optimism": "generated",
        "conceal": false,
        "returns": false,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest"
            }
        },
        "operations": [],
        "sites": {},
        "version": "8898cfb23f3791cade785cb0e06343972843ef4806f65713bc3cb9e287cab18b",
        "returnType": "void"
    },
    "m_43126b2abf6583944b8aec18795ac66e": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@explicit",
        "optimism": "generated",
        "conceal": false,
        "returns": true,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest"
            },
            "mutation": {
                "kind": "context",
                "type": "ZeroForLaravel\\Mutation\\Mutation"
            }
        },
        "operations": [
            {
                "op": "set",
                "name": "task",
                "value": {
                    "op": "create",
                    "model": "Task",
                    "class": "ZeroForLaravel\\Tests\\Fixtures\\Task",
                    "site": "key:task",
                    "data": {
                        "op": "validated",
                        "base": {
                            "op": "local",
                            "name": "request",
                            "result": {
                                "kind": "request",
                                "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest"
                            }
                        },
                        "result": {
                            "kind": "input",
                            "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest",
                            "path": ""
                        }
                    },
                    "result": {
                        "kind": "model",
                        "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
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
                                    "name": "task",
                                    "result": {
                                        "kind": "model",
                                        "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
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
            "key:task": "uuid"
        },
        "version": "43126b2abf6583944b8aec18795ac66e323dbe18685063dc7ca74c509858f668",
        "returnType": "array"
    },
    "m_727f77288e1fb39207d7055b6839fe87": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@structured",
        "optimism": "disabled",
        "conceal": false,
        "returns": true,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest"
            }
        },
        "operations": [],
        "sites": {},
        "version": "727f77288e1fb39207d7055b6839fe8709ab264e3104279850e845baa863577e",
        "returnType": "array"
    },
    "m_624bfebd94252d65bfee746141e9dd73": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@projected",
        "optimism": "custom",
        "conceal": false,
        "returns": true,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationRequest"
            }
        },
        "operations": [],
        "sites": {},
        "version": "624bfebd94252d65bfee746141e9dd7326b4ba1b0e2b4d30792b77aaaa5e9fe9",
        "returnType": "array"
    },
    "m_6ea2ec9639962486db7896d486421963": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@bulk",
        "optimism": "generated",
        "conceal": false,
        "returns": false,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\BulkMutationRequest"
            }
        },
        "operations": [
            {
                "op": "set",
                "name": "data",
                "value": {
                    "op": "validated",
                    "base": {
                        "op": "local",
                        "name": "request",
                        "result": {
                            "kind": "request",
                            "type": "ZeroForLaravel\\Tests\\Fixtures\\BulkMutationRequest"
                        }
                    },
                    "result": {
                        "kind": "input",
                        "type": "ZeroForLaravel\\Tests\\Fixtures\\BulkMutationRequest",
                        "path": ""
                    }
                }
            },
            {
                "op": "foreach",
                "value": {
                    "op": "get",
                    "base": {
                        "op": "local",
                        "name": "data",
                        "result": {
                            "kind": "input",
                            "type": "ZeroForLaravel\\Tests\\Fixtures\\BulkMutationRequest",
                            "path": ""
                        }
                    },
                    "key": {
                        "op": "literal",
                        "value": "items"
                    },
                    "result": {
                        "kind": "input",
                        "type": "ZeroForLaravel\\Tests\\Fixtures\\BulkMutationRequest",
                        "path": "items"
                    }
                },
                "name": "item",
                "max": 20,
                "body": [
                    {
                        "op": "expression",
                        "value": {
                            "op": "create",
                            "model": "Task",
                            "class": "ZeroForLaravel\\Tests\\Fixtures\\Task",
                            "site": "s0",
                            "data": {
                                "op": "object",
                                "items": [
                                    {
                                        "key": "title",
                                        "value": {
                                            "op": "get",
                                            "base": {
                                                "op": "local",
                                                "name": "item",
                                                "result": {
                                                    "kind": "input",
                                                    "type": "ZeroForLaravel\\Tests\\Fixtures\\BulkMutationRequest",
                                                    "path": "items.*"
                                                }
                                            },
                                            "key": {
                                                "op": "literal",
                                                "value": "title"
                                            },
                                            "result": {
                                                "kind": "input",
                                                "type": "ZeroForLaravel\\Tests\\Fixtures\\BulkMutationRequest",
                                                "path": "items.*.title"
                                            }
                                        }
                                    },
                                    {
                                        "key": "completed_at",
                                        "value": {
                                            "op": "get",
                                            "base": {
                                                "op": "local",
                                                "name": "item",
                                                "result": {
                                                    "kind": "input",
                                                    "type": "ZeroForLaravel\\Tests\\Fixtures\\BulkMutationRequest",
                                                    "path": "items.*"
                                                }
                                            },
                                            "key": {
                                                "op": "literal",
                                                "value": "at"
                                            },
                                            "result": {
                                                "kind": "input",
                                                "type": "ZeroForLaravel\\Tests\\Fixtures\\BulkMutationRequest",
                                                "path": "items.*.at"
                                            }
                                        }
                                    }
                                ]
                            },
                            "result": {
                                "kind": "model",
                                "type": "ZeroForLaravel\\Tests\\Fixtures\\Task"
                            }
                        }
                    }
                ]
            }
        ],
        "sites": {
            "s0": "uuid"
        },
        "version": "6ea2ec9639962486db7896d4864219639de0bc86e42d8c14f4a8001ce0eecd6d",
        "returnType": "void"
    }
} as const;
export type Input0 = {readonly "title": string; readonly "done"?: boolean | undefined; readonly "reference"?: string | undefined};
export type ServerResult0 = {readonly "id": string};
export type ClientResult0 = {readonly "id": string};
export const inputSchema0 = z.strictObject({"title": z.string().refine(value => Array.from(value).length <= 100), "done": z.boolean().optional(), "reference": z.string().uuid().optional()});
export const serverResultSchema0 = z.strictObject({"id": z.string()});
export const clientResultSchema0 = z.strictObject({"id": z.string()});
const codec0 = {input: inputSchema0, encode: (input: Input0) => ({"title": input["title"], ...(input["done"] === undefined ? {} : {"done": input["done"]}), ...(input["reference"] === undefined ? {} : {"reference": input["reference"]})}), serverResult: serverResultSchema0, clientResult: clientResultSchema0};
export type Input1 = {readonly "title": string; readonly "done"?: boolean | undefined; readonly "reference"?: string | undefined};
export type ServerResult1 = {readonly "before": boolean; readonly "after": boolean};
export type ClientResult1 = ({readonly "before": boolean; readonly "after": boolean}) | (undefined);
export const inputSchema1 = z.strictObject({"title": z.string().refine(value => Array.from(value).length <= 100), "done": z.boolean().optional(), "reference": z.string().uuid().optional()});
export const serverResultSchema1 = z.strictObject({"before": z.boolean(), "after": z.boolean()});
export const clientResultSchema1 = z.union([z.strictObject({"before": z.boolean(), "after": z.boolean()}), z.undefined()]);
const codec1 = {input: inputSchema1, encode: (input: Input1) => ({"title": input["title"], ...(input["done"] === undefined ? {} : {"done": input["done"]}), ...(input["reference"] === undefined ? {} : {"reference": input["reference"]})}), serverResult: serverResultSchema1, clientResult: clientResultSchema1};
export type Input2 = {readonly "title": string; readonly "done"?: boolean | undefined; readonly "reference"?: string | undefined};
export type ServerResult2 = null;
export type ClientResult2 = undefined;
export const inputSchema2 = z.strictObject({"title": z.string().refine(value => Array.from(value).length <= 100), "done": z.boolean().optional(), "reference": z.string().uuid().optional()});
export const serverResultSchema2 = z.null();
export const clientResultSchema2 = z.undefined();
const codec2 = {input: inputSchema2, encode: (input: Input2) => ({"title": input["title"], ...(input["done"] === undefined ? {} : {"done": input["done"]}), ...(input["reference"] === undefined ? {} : {"reference": input["reference"]})}), serverResult: serverResultSchema2, clientResult: clientResultSchema2};
export type Input3 = {readonly "title": string; readonly "done"?: boolean | undefined; readonly "reference"?: string | undefined};
export type ServerResult3 = undefined;
export type ClientResult3 = undefined;
export const inputSchema3 = z.strictObject({"title": z.string().refine(value => Array.from(value).length <= 100), "done": z.boolean().optional(), "reference": z.string().uuid().optional()});
export const serverResultSchema3 = z.undefined();
export const clientResultSchema3 = z.undefined();
const codec3 = {input: inputSchema3, encode: (input: Input3) => ({"title": input["title"], ...(input["done"] === undefined ? {} : {"done": input["done"]}), ...(input["reference"] === undefined ? {} : {"reference": input["reference"]})}), serverResult: serverResultSchema3, clientResult: clientResultSchema3};
export type Input4 = {readonly "title": string; readonly "done"?: boolean | undefined; readonly "reference"?: string | undefined};
export type ServerResult4 = {readonly "id": string};
export type ClientResult4 = {readonly "id": string};
export const inputSchema4 = z.strictObject({"title": z.string().refine(value => Array.from(value).length <= 100), "done": z.boolean().optional(), "reference": z.string().uuid().optional()});
export const serverResultSchema4 = z.strictObject({"id": z.string()});
export const clientResultSchema4 = z.strictObject({"id": z.string()});
const codec4 = {input: inputSchema4, encode: (input: Input4) => ({"title": input["title"], ...(input["done"] === undefined ? {} : {"done": input["done"]}), ...(input["reference"] === undefined ? {} : {"reference": input["reference"]})}), serverResult: serverResultSchema4, clientResult: clientResultSchema4};
export type Input5 = {readonly "title": string; readonly "done"?: boolean | undefined; readonly "reference"?: string | undefined};
export type ServerResult5 = {readonly "items": ReadonlyArray<string>; readonly "meta": {readonly "note"?: string | undefined}};
export type ClientResult5 = undefined;
export const inputSchema5 = z.strictObject({"title": z.string().refine(value => Array.from(value).length <= 100), "done": z.boolean().optional(), "reference": z.string().uuid().optional()});
export const serverResultSchema5 = z.strictObject({"items": z.array(z.string()), "meta": z.strictObject({"note": z.string().optional()})});
export const clientResultSchema5 = z.undefined();
const codec5 = {input: inputSchema5, encode: (input: Input5) => ({"title": input["title"], ...(input["done"] === undefined ? {} : {"done": input["done"]}), ...(input["reference"] === undefined ? {} : {"reference": input["reference"]})}), serverResult: serverResultSchema5, clientResult: clientResultSchema5};
export type Input6 = {readonly "title": string; readonly "done"?: boolean | undefined; readonly "reference"?: string | undefined};
export type ServerResult6 = {readonly "id": string};
export type ClientResult6 = ({readonly "pending": boolean}) | (undefined);
export const inputSchema6 = z.strictObject({"title": z.string().refine(value => Array.from(value).length <= 100), "done": z.boolean().optional(), "reference": z.string().uuid().optional()});
export const serverResultSchema6 = z.strictObject({"id": z.string()});
export const clientResultSchema6 = z.union([z.strictObject({"pending": z.boolean()}), z.undefined()]);
const codec6 = {input: inputSchema6, encode: (input: Input6) => ({"title": input["title"], ...(input["done"] === undefined ? {} : {"done": input["done"]}), ...(input["reference"] === undefined ? {} : {"reference": input["reference"]})}), serverResult: serverResultSchema6, clientResult: clientResultSchema6};
export type Input7 = {readonly "items": ReadonlyArray<{readonly "key": string; readonly "title": string; readonly "at": Date | null}>};
export type ServerResult7 = undefined;
export type ClientResult7 = undefined;
export const inputSchema7 = z.strictObject({"items": z.array(z.strictObject({"key": z.string().refine(value => Array.from(value).length <= 100), "title": z.string().refine(value => Array.from(value).length <= 100), "at": z.date().nullable()})).max(20)});
export const serverResultSchema7 = z.undefined();
export const clientResultSchema7 = z.undefined();
const codec7 = {input: inputSchema7, encode: (input: Input7) => ({"items": input["items"].map(item => ({"key": item["key"], "title": item["title"], "at": (item["at"] === null ? null : item["at"].toISOString())}))}), serverResult: serverResultSchema7, clientResult: clientResultSchema7};
export function createControllerMutationBindings<S extends Schema, C extends ClientContext = ClientContext>(schema: S, models: Readonly<Record<string, ModelContract>>, projections: {"ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@projected": NonNullable<ControllerMutationOptions<Input6, ServerResult6, ClientResult6, S, C>["optimistic"]>}) {
  const codecs = {
    "m_db13b3ecab1502b8b4572340a371a384": {...codec0},
    "m_5de6ec75db4b013bb4cc031cdedf8c0a": {...codec1},
    "m_c22851fa301a9e5a235ce892065130e5": {...codec2},
    "m_8898cfb23f3791cade785cb0e0634397": {...codec3},
    "m_43126b2abf6583944b8aec18795ac66e": {...codec4},
    "m_727f77288e1fb39207d7055b6839fe87": {...codec5},
    "m_624bfebd94252d65bfee746141e9dd73": {...codec6, optimistic: projections["ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@projected"]},
    "m_6ea2ec9639962486db7896d486421963": {...codec7},
  };
  const runtime = createControllerMutations<S, C>(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@store": runtime.bind("m_db13b3ecab1502b8b4572340a371a384", codecs["m_db13b3ecab1502b8b4572340a371a384"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@complete": runtime.bind("m_5de6ec75db4b013bb4cc031cdedf8c0a", codecs["m_5de6ec75db4b013bb4cc031cdedf8c0a"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@authoritative": runtime.bind("m_c22851fa301a9e5a235ce892065130e5", codecs["m_c22851fa301a9e5a235ce892065130e5"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@noResult": runtime.bind("m_8898cfb23f3791cade785cb0e0634397", codecs["m_8898cfb23f3791cade785cb0e0634397"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@explicit": runtime.bind("m_43126b2abf6583944b8aec18795ac66e", codecs["m_43126b2abf6583944b8aec18795ac66e"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@structured": runtime.bind("m_727f77288e1fb39207d7055b6839fe87", codecs["m_727f77288e1fb39207d7055b6839fe87"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@projected": runtime.bind("m_624bfebd94252d65bfee746141e9dd73", codecs["m_624bfebd94252d65bfee746141e9dd73"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@bulk": runtime.bind("m_6ea2ec9639962486db7896d486421963", codecs["m_6ea2ec9639962486db7896d486421963"]),
  }};
}
