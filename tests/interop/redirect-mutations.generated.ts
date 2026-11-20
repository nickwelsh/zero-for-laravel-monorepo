// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ClientContext, type ModelContract, type ControllerMutationOptions} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_039d05e6dc569f75c6ad6e3eca811ab0": {
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
        "version": "039d05e6dc569f75c6ad6e3eca811ab09468942a3df44f13569cea51e2ce9fd0",
        "returnType": "Illuminate\\Http\\RedirectResponse",
        "navigateAfter": "local"
    },
    "m_7cd05eded4ba7ad5b9164e1b958c9527": {
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
        "version": "7cd05eded4ba7ad5b9164e1b958c952795cf472282ac34a09aa4180f3592cba0",
        "returnType": "Illuminate\\Http\\RedirectResponse",
        "navigateAfter": "server"
    },
    "m_2f94de498b122d46dc49dde47e9a2424": {
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
        "version": "2f94de498b122d46dc49dde47e9a24240dae754036c336b915a2d1aa4dfdce30",
        "returnType": "Illuminate\\Http\\RedirectResponse",
        "navigateAfter": "local"
    },
    "m_9efcafabb4b170631d4e14da76f28bd3": {
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
        "version": "9efcafabb4b170631d4e14da76f28bd39a1d6962b666176d751ab69bf060bdd9",
        "returnType": "Illuminate\\Http\\RedirectResponse",
        "navigateAfter": "local"
    },
    "m_cc52e4144bc306696d30a255399b8fcf": {
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
        "version": "cc52e4144bc306696d30a255399b8fcfdf7ee97d480e19be7860c16ede5de4ec",
        "returnType": "Illuminate\\Http\\RedirectResponse",
        "navigateAfter": "server"
    },
    "m_2ad2eb19967119d7a249d616150303df": {
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
        "version": "2ad2eb19967119d7a249d616150303df5c96d0e385326befe7aab5a4e225fa5a",
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
    "m_039d05e6dc569f75c6ad6e3eca811ab0": {...codec0},
    "m_7cd05eded4ba7ad5b9164e1b958c9527": {...codec1},
    "m_2f94de498b122d46dc49dde47e9a2424": {...codec2},
    "m_9efcafabb4b170631d4e14da76f28bd3": {...codec3},
    "m_cc52e4144bc306696d30a255399b8fcf": {...codec4},
    "m_2ad2eb19967119d7a249d616150303df": {...codec5, optimistic: projections["ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@projected"]},
  };
  const runtime = createControllerMutations<S, C>(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@store": runtime.bind("m_039d05e6dc569f75c6ad6e3eca811ab0", codecs["m_039d05e6dc569f75c6ad6e3eca811ab0"]),
    "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@confirmed": runtime.bind("m_7cd05eded4ba7ad5b9164e1b958c9527", codecs["m_7cd05eded4ba7ad5b9164e1b958c9527"]),
    "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@bound": runtime.bind("m_2f94de498b122d46dc49dde47e9a2424", codecs["m_2f94de498b122d46dc49dde47e9a2424"]),
    "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@slug": runtime.bind("m_9efcafabb4b170631d4e14da76f28bd3", codecs["m_9efcafabb4b170631d4e14da76f28bd3"]),
    "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@disabled": runtime.bind("m_cc52e4144bc306696d30a255399b8fcf", codecs["m_cc52e4144bc306696d30a255399b8fcf"]),
    "ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@projected": runtime.bind("m_2ad2eb19967119d7a249d616150303df", codecs["m_2ad2eb19967119d7a249d616150303df"]),
  }};
}
