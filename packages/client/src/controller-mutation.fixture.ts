// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ClientContext, type ModelContract, type ControllerMutationOptions} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_6c8ecc003fa9e8b9cfbfe540047cfd1f": {
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
        "version": "6c8ecc003fa9e8b9cfbfe540047cfd1ff0a44bf489137a44a42892ca743dfd41",
        "returnType": "array"
    },
    "m_0dfc7363569d9b5b8dd60a2df4ad0e0b": {
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
        "version": "0dfc7363569d9b5b8dd60a2df4ad0e0b6f705ea0ac36245abe196601deef6ea2",
        "returnType": "array"
    },
    "m_c3d02bb72e104f54b0d7e696b019e851": {
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
        "version": "c3d02bb72e104f54b0d7e696b019e8515eb9eb56e857ff739e0871acfb5a63cc",
        "returnType": "null"
    },
    "m_4ada10895bf6ee60a5af5ef64a11d3f2": {
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
        "version": "4ada10895bf6ee60a5af5ef64a11d3f2184aa3e1d83875f15b65164e22c8fe54",
        "returnType": "void"
    },
    "m_5c610c251a4f41e3e5b34f0b4da4a919": {
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
        "version": "5c610c251a4f41e3e5b34f0b4da4a919496da693591b559a48216be4f50fdebc",
        "returnType": "array"
    },
    "m_1f926d84f3fd20101eb3a55513a83a6e": {
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
        "version": "1f926d84f3fd20101eb3a55513a83a6e554f015d6843f17ba3feab0fd1bafae3",
        "returnType": "array"
    },
    "m_d62a1f8bd4d5adc00a27e208ec597b52": {
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
        "version": "d62a1f8bd4d5adc00a27e208ec597b521c91ef8a72fda266ab11f3950aa0c00d",
        "returnType": "array"
    },
    "m_7cfeed4c372f19ac7f390a4fbcff637f": {
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
        "version": "7cfeed4c372f19ac7f390a4fbcff637fc248a46132585709cd69c1a69f15fbf2",
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
    "m_6c8ecc003fa9e8b9cfbfe540047cfd1f": {...codec0},
    "m_0dfc7363569d9b5b8dd60a2df4ad0e0b": {...codec1},
    "m_c3d02bb72e104f54b0d7e696b019e851": {...codec2},
    "m_4ada10895bf6ee60a5af5ef64a11d3f2": {...codec3},
    "m_5c610c251a4f41e3e5b34f0b4da4a919": {...codec4},
    "m_1f926d84f3fd20101eb3a55513a83a6e": {...codec5},
    "m_d62a1f8bd4d5adc00a27e208ec597b52": {...codec6, optimistic: projections["ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@projected"]},
    "m_7cfeed4c372f19ac7f390a4fbcff637f": {...codec7},
  };
  const runtime = createControllerMutations<S, C>(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@store": runtime.bind("m_6c8ecc003fa9e8b9cfbfe540047cfd1f", codecs["m_6c8ecc003fa9e8b9cfbfe540047cfd1f"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@complete": runtime.bind("m_0dfc7363569d9b5b8dd60a2df4ad0e0b", codecs["m_0dfc7363569d9b5b8dd60a2df4ad0e0b"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@authoritative": runtime.bind("m_c3d02bb72e104f54b0d7e696b019e851", codecs["m_c3d02bb72e104f54b0d7e696b019e851"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@noResult": runtime.bind("m_4ada10895bf6ee60a5af5ef64a11d3f2", codecs["m_4ada10895bf6ee60a5af5ef64a11d3f2"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@explicit": runtime.bind("m_5c610c251a4f41e3e5b34f0b4da4a919", codecs["m_5c610c251a4f41e3e5b34f0b4da4a919"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@structured": runtime.bind("m_1f926d84f3fd20101eb3a55513a83a6e", codecs["m_1f926d84f3fd20101eb3a55513a83a6e"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@projected": runtime.bind("m_d62a1f8bd4d5adc00a27e208ec597b52", codecs["m_d62a1f8bd4d5adc00a27e208ec597b52"]),
    "ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@bulk": runtime.bind("m_7cfeed4c372f19ac7f390a4fbcff637f", codecs["m_7cfeed4c372f19ac7f390a4fbcff637f"]),
  }};
}
