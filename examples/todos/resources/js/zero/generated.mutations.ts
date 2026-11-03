// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ModelContract} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_e9ed7be710f65cffd56f7ac8eb178553": {
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
            }
        },
        "operations": [],
        "sites": {},
        "version": "e9ed7be710f65cffd56f7ac8eb17855386ceb15bb541d66b7f9b4457f1777de5",
        "returnType": "void"
    },
    "m_8f0a77561697415b203fcc5b0473dac3": {
        "action": "App\\Http\\Controllers\\ShareListController@destroy",
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
            }
        },
        "operations": [],
        "sites": {},
        "version": "8f0a77561697415b203fcc5b0473dac350688d311af31e093500c2979cacca30",
        "returnType": "void"
    },
    "m_4a38bdb340f3d8bb1511371ab0aa256b": {
        "action": "App\\Http\\Controllers\\TodoController@destroy",
        "optimism": "disabled",
        "conceal": true,
        "returns": false,
        "parameters": {
            "todo": {
                "kind": "model",
                "type": "App\\Models\\Todo"
            }
        },
        "operations": [],
        "sites": {},
        "version": "4a38bdb340f3d8bb1511371ab0aa256b2d80fd3c96152057a91936735d4a628b",
        "returnType": "void"
    },
    "m_d6ad8a42dabc2800057c53aabb25fefa": {
        "action": "App\\Http\\Controllers\\TodoController@store",
        "optimism": "disabled",
        "conceal": false,
        "returns": true,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "App\\Http\\Requests\\SaveTodoRequest"
            }
        },
        "operations": [],
        "sites": {},
        "version": "d6ad8a42dabc2800057c53aabb25fefa51064408f2a2d47293dfcddc5962d773",
        "returnType": "array"
    },
    "m_936327922164c93b492552079a7978fd": {
        "action": "App\\Http\\Controllers\\TodoController@update",
        "optimism": "disabled",
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
            }
        },
        "operations": [],
        "sites": {},
        "version": "936327922164c93b492552079a7978fd090fe2742e9cec244256dcc37fbb7744",
        "returnType": "void"
    },
    "m_c5e29784e7a0d080158a8be8cde7343c": {
        "action": "App\\Http\\Controllers\\TodoListController@destroy",
        "optimism": "disabled",
        "conceal": true,
        "returns": false,
        "parameters": {
            "list": {
                "kind": "model",
                "type": "App\\Models\\TodoList"
            }
        },
        "operations": [],
        "sites": {},
        "version": "c5e29784e7a0d080158a8be8cde7343cb96bd3e050090aafc747f50d03ff5f09",
        "returnType": "void"
    },
    "m_d0638885b43f1162223e34355ddb1559": {
        "action": "App\\Http\\Controllers\\TodoListController@store",
        "optimism": "disabled",
        "conceal": false,
        "returns": true,
        "parameters": {
            "request": {
                "kind": "request",
                "type": "App\\Http\\Requests\\SaveTodoListRequest"
            }
        },
        "operations": [],
        "sites": {},
        "version": "d0638885b43f1162223e34355ddb1559573678737388b0b2a0aca3aa944b07b7",
        "returnType": "array"
    },
    "m_ce97dc8c162afb69231bc53ca49aafd6": {
        "action": "App\\Http\\Controllers\\TodoListController@update",
        "optimism": "disabled",
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
            }
        },
        "operations": [],
        "sites": {},
        "version": "ce97dc8c162afb69231bc53ca49aafd64acf9bb6189a0990e4f8390bc4f6e718",
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
export type Input1 = {readonly "email": string};
export type ServerResult1 = undefined;
export type ClientResult1 = undefined;
export const inputSchema1 = z.strictObject({"email": z.string()});
export const serverResultSchema1 = z.undefined();
export const clientResultSchema1 = z.undefined();
const codec1 = {input: inputSchema1, encode: (input: Input1) => ({"email": input["email"]}), serverResult: serverResultSchema1, clientResult: clientResultSchema1};
export type Input2 = {};
export type ServerResult2 = undefined;
export type ClientResult2 = undefined;
export const inputSchema2 = z.strictObject({});
export const serverResultSchema2 = z.undefined();
export const clientResultSchema2 = z.undefined();
const codec2 = {input: inputSchema2, encode: (input: Input2) => ({}), serverResult: serverResultSchema2, clientResult: clientResultSchema2};
export type Input3 = {readonly "list_id"?: string | undefined; readonly "title"?: string | undefined; readonly "completed"?: boolean | undefined};
export type ServerResult3 = {readonly "id": string};
export type ClientResult3 = undefined;
export const inputSchema3 = z.strictObject({"list_id": z.string().uuid().optional(), "title": z.string().refine(value => Array.from(value).length <= 200).optional(), "completed": z.boolean().optional()});
export const serverResultSchema3 = z.strictObject({"id": z.string()});
export const clientResultSchema3 = z.undefined();
const codec3 = {input: inputSchema3, encode: (input: Input3) => ({...(input["list_id"] === undefined ? {} : {"list_id": input["list_id"]}), ...(input["title"] === undefined ? {} : {"title": input["title"]}), ...(input["completed"] === undefined ? {} : {"completed": input["completed"]})}), serverResult: serverResultSchema3, clientResult: clientResultSchema3};
export type Input4 = {readonly "list_id"?: string | undefined; readonly "title"?: string | undefined; readonly "completed"?: boolean | undefined};
export type ServerResult4 = undefined;
export type ClientResult4 = undefined;
export const inputSchema4 = z.strictObject({"list_id": z.string().uuid().optional(), "title": z.string().refine(value => Array.from(value).length <= 200).optional(), "completed": z.boolean().optional()});
export const serverResultSchema4 = z.undefined();
export const clientResultSchema4 = z.undefined();
const codec4 = {input: inputSchema4, encode: (input: Input4) => ({...(input["list_id"] === undefined ? {} : {"list_id": input["list_id"]}), ...(input["title"] === undefined ? {} : {"title": input["title"]}), ...(input["completed"] === undefined ? {} : {"completed": input["completed"]})}), serverResult: serverResultSchema4, clientResult: clientResultSchema4};
export type Input5 = {};
export type ServerResult5 = undefined;
export type ClientResult5 = undefined;
export const inputSchema5 = z.strictObject({});
export const serverResultSchema5 = z.undefined();
export const clientResultSchema5 = z.undefined();
const codec5 = {input: inputSchema5, encode: (input: Input5) => ({}), serverResult: serverResultSchema5, clientResult: clientResultSchema5};
export type Input6 = {readonly "name"?: string | undefined};
export type ServerResult6 = {readonly "id": string};
export type ClientResult6 = undefined;
export const inputSchema6 = z.strictObject({"name": z.string().refine(value => Array.from(value).length <= 100).optional()});
export const serverResultSchema6 = z.strictObject({"id": z.string()});
export const clientResultSchema6 = z.undefined();
const codec6 = {input: inputSchema6, encode: (input: Input6) => ({...(input["name"] === undefined ? {} : {"name": input["name"]})}), serverResult: serverResultSchema6, clientResult: clientResultSchema6};
export type Input7 = {readonly "name"?: string | undefined};
export type ServerResult7 = undefined;
export type ClientResult7 = undefined;
export const inputSchema7 = z.strictObject({"name": z.string().refine(value => Array.from(value).length <= 100).optional()});
export const serverResultSchema7 = z.undefined();
export const clientResultSchema7 = z.undefined();
const codec7 = {input: inputSchema7, encode: (input: Input7) => ({...(input["name"] === undefined ? {} : {"name": input["name"]})}), serverResult: serverResultSchema7, clientResult: clientResultSchema7};
export function createControllerMutationBindings<S extends Schema>(schema: S, models: Readonly<Record<string, ModelContract>>) {
  const codecs = {
    "m_e9ed7be710f65cffd56f7ac8eb178553": {...codec0},
    "m_8f0a77561697415b203fcc5b0473dac3": {...codec1},
    "m_4a38bdb340f3d8bb1511371ab0aa256b": {...codec2},
    "m_d6ad8a42dabc2800057c53aabb25fefa": {...codec3},
    "m_936327922164c93b492552079a7978fd": {...codec4},
    "m_c5e29784e7a0d080158a8be8cde7343c": {...codec5},
    "m_d0638885b43f1162223e34355ddb1559": {...codec6},
    "m_ce97dc8c162afb69231bc53ca49aafd6": {...codec7},
  };
  const runtime = createControllerMutations(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "App\\Http\\Controllers\\ShareListController@__invoke": runtime.bind("m_e9ed7be710f65cffd56f7ac8eb178553", codecs["m_e9ed7be710f65cffd56f7ac8eb178553"]),
    "App\\Http\\Controllers\\ShareListController@destroy": runtime.bind("m_8f0a77561697415b203fcc5b0473dac3", codecs["m_8f0a77561697415b203fcc5b0473dac3"]),
    "App\\Http\\Controllers\\TodoController@destroy": runtime.bind("m_4a38bdb340f3d8bb1511371ab0aa256b", codecs["m_4a38bdb340f3d8bb1511371ab0aa256b"]),
    "App\\Http\\Controllers\\TodoController@store": runtime.bind("m_d6ad8a42dabc2800057c53aabb25fefa", codecs["m_d6ad8a42dabc2800057c53aabb25fefa"]),
    "App\\Http\\Controllers\\TodoController@update": runtime.bind("m_936327922164c93b492552079a7978fd", codecs["m_936327922164c93b492552079a7978fd"]),
    "App\\Http\\Controllers\\TodoListController@destroy": runtime.bind("m_c5e29784e7a0d080158a8be8cde7343c", codecs["m_c5e29784e7a0d080158a8be8cde7343c"]),
    "App\\Http\\Controllers\\TodoListController@store": runtime.bind("m_d0638885b43f1162223e34355ddb1559", codecs["m_d0638885b43f1162223e34355ddb1559"]),
    "App\\Http\\Controllers\\TodoListController@update": runtime.bind("m_ce97dc8c162afb69231bc53ca49aafd6", codecs["m_ce97dc8c162afb69231bc53ca49aafd6"]),
  }};
}
