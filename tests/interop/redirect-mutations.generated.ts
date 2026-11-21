// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ClientContext, type ModelContract, type ControllerMutationOptions} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_d552463ee25b911b795c83cf6238347a": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@store",
        "optimism": "generated",
        "conceal": false,
        "returns": false,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationRequest"
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
                    "model": "Task",
                    "class": "ZeroForLaravel\\Tests\\Fixtures\\Task",
                    "site": "s0",
                    "data": {
                        "op": "safeExcept",
                        "base": {
                            "op": "local",
                            "name": "request",
                            "result": {
                                "kind": "request",
                                "type": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationRequest"
                            }
                        },
                        "keys": [
                            "open_after_saving"
                        ]
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
                    "op": "redirectWhen",
                    "request": "request",
                    "field": "open_after_saving",
                    "predict": true,
                    "yes": {
                        "op": "visit",
                        "route": "todos.show",
                        "parameters": [
                            {
                                "name": "todo",
                                "value": {
                                    "op": "local",
                                    "name": "todo"
                                },
                                "model": true,
                                "bindingField": null,
                                "predictKey": "id"
                            }
                        ]
                    },
                    "no": {
                        "op": "stay"
                    }
                }
            }
        ],
        "sites": {
            "s0": "uuid"
        },
        "version": "d552463ee25b911b795c83cf6238347a7fc9d58708703a5ede1fa15599868d37",
        "returnType": "Illuminate\\Http\\RedirectResponse",
        "navigateAfter": "local"
    },
    "m_97e9bed0f85d6db7fbab4d77d917812d": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@confirmed",
        "optimism": "generated",
        "conceal": false,
        "returns": false,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationRequest"
            }
        },
        "operations": [
            {
                "op": "set",
                "name": "todo",
                "value": {
                    "op": "create",
                    "model": "Task",
                    "class": "ZeroForLaravel\\Tests\\Fixtures\\Task",
                    "site": "s0",
                    "data": {
                        "op": "safeExcept",
                        "base": {
                            "op": "local",
                            "name": "request",
                            "result": {
                                "kind": "request",
                                "type": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationRequest"
                            }
                        },
                        "keys": [
                            "open_after_saving"
                        ]
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
                    "op": "visit",
                    "route": "todos.show",
                    "parameters": [
                        {
                            "name": "todo",
                            "value": {
                                "op": "local",
                                "name": "todo"
                            },
                            "model": true,
                            "bindingField": null,
                            "predictKey": "id"
                        }
                    ]
                }
            }
        ],
        "sites": {
            "s0": "uuid"
        },
        "version": "97e9bed0f85d6db7fbab4d77d917812d26857a4536f9a864a67ecff445c85133",
        "returnType": "Illuminate\\Http\\RedirectResponse",
        "navigateAfter": "server"
    },
    "m_1bb929cbee6aae4a63a79daeb0f13e4b": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@bound",
        "optimism": "generated",
        "conceal": false,
        "returns": false,
        "parameters": {
            "todo": {
                "kind": "model",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\Task",
                "predictBinding": true
            }
        },
        "operations": [
            {
                "op": "return",
                "value": {
                    "op": "visit",
                    "route": "todos.show",
                    "parameters": [
                        {
                            "name": "todo",
                            "value": {
                                "op": "local",
                                "name": "todo"
                            },
                            "model": true,
                            "bindingField": null,
                            "predictKey": "id"
                        }
                    ]
                }
            }
        ],
        "sites": {},
        "version": "1bb929cbee6aae4a63a79daeb0f13e4b996f9e6fab97607e0134822baac870c8",
        "returnType": "Illuminate\\Http\\RedirectResponse",
        "navigateAfter": "local"
    },
    "m_f9696f661b9e1d861e4e7d720fb3c9fd": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@slug",
        "optimism": "generated",
        "conceal": false,
        "returns": false,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationRequest"
            }
        },
        "operations": [
            {
                "op": "set",
                "name": "todo",
                "value": {
                    "op": "create",
                    "model": "Task",
                    "class": "ZeroForLaravel\\Tests\\Fixtures\\Task",
                    "site": "s0",
                    "data": {
                        "op": "safeExcept",
                        "base": {
                            "op": "local",
                            "name": "request",
                            "result": {
                                "kind": "request",
                                "type": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationRequest"
                            }
                        },
                        "keys": [
                            "open_after_saving"
                        ]
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
                    "op": "visit",
                    "route": "todos.title",
                    "parameters": [
                        {
                            "name": "todo",
                            "value": {
                                "op": "local",
                                "name": "todo"
                            },
                            "model": true,
                            "bindingField": "title",
                            "predictKey": null
                        }
                    ]
                }
            }
        ],
        "sites": {
            "s0": "uuid"
        },
        "version": "f9696f661b9e1d861e4e7d720fb3c9fd606f86bf9f8e51b7d637aecb46ca4c16",
        "returnType": "Illuminate\\Http\\RedirectResponse",
        "navigateAfter": "local"
    },
    "m_bdd9058859b4d1f85a18877bf17e04d5": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@disabled",
        "optimism": "disabled",
        "conceal": false,
        "returns": false,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationRequest"
            }
        },
        "operations": [
            {
                "op": "expression",
                "value": {
                    "op": "create",
                    "model": "Task",
                    "class": "ZeroForLaravel\\Tests\\Fixtures\\Task",
                    "site": "s0",
                    "data": {
                        "op": "safeExcept",
                        "base": {
                            "op": "local",
                            "name": "request",
                            "result": {
                                "kind": "request",
                                "type": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationRequest"
                            }
                        },
                        "keys": [
                            "open_after_saving"
                        ]
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
                    "op": "stay"
                }
            }
        ],
        "sites": {
            "s0": "uuid"
        },
        "version": "bdd9058859b4d1f85a18877bf17e04d5e778c0fd1fa9a7e94417ffdfe5b96103",
        "returnType": "Illuminate\\Http\\RedirectResponse",
        "navigateAfter": "server"
    },
    "m_ed95fa22c0ffd8696eae8b7febda90c1": {
        "action": "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@projected",
        "optimism": "custom",
        "conceal": false,
        "returns": false,
        "parameters": {},
        "operations": [
            {
                "op": "return",
                "value": {
                    "op": "visit",
                    "route": "todos.index",
                    "parameters": []
                }
            }
        ],
        "sites": {},
        "version": "ed95fa22c0ffd8696eae8b7febda90c1447119ca0347e8f2036c90c703cca5a0",
        "returnType": "Illuminate\\Http\\RedirectResponse",
        "navigateAfter": "server"
    }
} as const;
export type Input0 = {readonly "title": string; readonly "open_after_saving"?: boolean | undefined};
export type ServerResult0 = undefined;
export type ClientResult0 = undefined;
export const inputSchema0 = z.strictObject({"title": z.string(), "open_after_saving": z.boolean().optional()});
export const serverResultSchema0 = z.undefined();
export const clientResultSchema0 = z.undefined();
const codec0 = {input: inputSchema0, encode: (input: Input0) => ({"title": input["title"], ...(input["open_after_saving"] === undefined ? {} : {"open_after_saving": input["open_after_saving"]})}), serverResult: serverResultSchema0, clientResult: clientResultSchema0};
export type Input1 = {readonly "title": string; readonly "open_after_saving"?: boolean | undefined};
export type ServerResult1 = undefined;
export type ClientResult1 = undefined;
export const inputSchema1 = z.strictObject({"title": z.string(), "open_after_saving": z.boolean().optional()});
export const serverResultSchema1 = z.undefined();
export const clientResultSchema1 = z.undefined();
const codec1 = {input: inputSchema1, encode: (input: Input1) => ({"title": input["title"], ...(input["open_after_saving"] === undefined ? {} : {"open_after_saving": input["open_after_saving"]})}), serverResult: serverResultSchema1, clientResult: clientResultSchema1};
export type Input2 = {};
export type ServerResult2 = undefined;
export type ClientResult2 = undefined;
export const inputSchema2 = z.strictObject({});
export const serverResultSchema2 = z.undefined();
export const clientResultSchema2 = z.undefined();
const codec2 = {input: inputSchema2, encode: (_input: Input2) => ({}), serverResult: serverResultSchema2, clientResult: clientResultSchema2};
export type Input3 = {readonly "title": string; readonly "open_after_saving"?: boolean | undefined};
export type ServerResult3 = undefined;
export type ClientResult3 = undefined;
export const inputSchema3 = z.strictObject({"title": z.string(), "open_after_saving": z.boolean().optional()});
export const serverResultSchema3 = z.undefined();
export const clientResultSchema3 = z.undefined();
const codec3 = {input: inputSchema3, encode: (input: Input3) => ({"title": input["title"], ...(input["open_after_saving"] === undefined ? {} : {"open_after_saving": input["open_after_saving"]})}), serverResult: serverResultSchema3, clientResult: clientResultSchema3};
export type Input4 = {readonly "title": string; readonly "open_after_saving"?: boolean | undefined};
export type ServerResult4 = undefined;
export type ClientResult4 = undefined;
export const inputSchema4 = z.strictObject({"title": z.string(), "open_after_saving": z.boolean().optional()});
export const serverResultSchema4 = z.undefined();
export const clientResultSchema4 = z.undefined();
const codec4 = {input: inputSchema4, encode: (input: Input4) => ({"title": input["title"], ...(input["open_after_saving"] === undefined ? {} : {"open_after_saving": input["open_after_saving"]})}), serverResult: serverResultSchema4, clientResult: clientResultSchema4};
export type Input5 = {};
export type ServerResult5 = undefined;
export type ClientResult5 = undefined;
export const inputSchema5 = z.strictObject({});
export const serverResultSchema5 = z.undefined();
export const clientResultSchema5 = z.undefined();
const codec5 = {input: inputSchema5, encode: (_input: Input5) => ({}), serverResult: serverResultSchema5, clientResult: clientResultSchema5};
export function createControllerMutationBindings<S extends Schema, C extends ClientContext = ClientContext>(schema: S, models: Readonly<Record<string, ModelContract>>, projections: {"ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@projected": NonNullable<ControllerMutationOptions<Input5, ServerResult5, ClientResult5, S, C>["optimistic"]>}) {
  const codecs = {
    "m_d552463ee25b911b795c83cf6238347a": {...codec0},
    "m_97e9bed0f85d6db7fbab4d77d917812d": {...codec1},
    "m_1bb929cbee6aae4a63a79daeb0f13e4b": {...codec2},
    "m_f9696f661b9e1d861e4e7d720fb3c9fd": {...codec3},
    "m_bdd9058859b4d1f85a18877bf17e04d5": {...codec4},
    "m_ed95fa22c0ffd8696eae8b7febda90c1": {...codec5, optimistic: projections["ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@projected"]},
  };
  const runtime = createControllerMutations<S, C>(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@store": runtime.bind("m_d552463ee25b911b795c83cf6238347a", codecs["m_d552463ee25b911b795c83cf6238347a"]),
    "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@confirmed": runtime.bind("m_97e9bed0f85d6db7fbab4d77d917812d", codecs["m_97e9bed0f85d6db7fbab4d77d917812d"]),
    "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@bound": runtime.bind("m_1bb929cbee6aae4a63a79daeb0f13e4b", codecs["m_1bb929cbee6aae4a63a79daeb0f13e4b"]),
    "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@slug": runtime.bind("m_f9696f661b9e1d861e4e7d720fb3c9fd", codecs["m_f9696f661b9e1d861e4e7d720fb3c9fd"]),
    "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@disabled": runtime.bind("m_bdd9058859b4d1f85a18877bf17e04d5", codecs["m_bdd9058859b4d1f85a18877bf17e04d5"]),
    "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@projected": runtime.bind("m_ed95fa22c0ffd8696eae8b7febda90c1", codecs["m_ed95fa22c0ffd8696eae8b7febda90c1"]),
  }};
}
