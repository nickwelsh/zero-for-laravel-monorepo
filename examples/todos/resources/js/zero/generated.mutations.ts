// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ClientContext, type ModelContract} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_9a2016beb6e3f9b7eea8e8dfd916eaaf": {
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
        "version": "9a2016beb6e3f9b7eea8e8dfd916eaaf63c0ca76159b69c4742fe66320b61218",
        "returnType": "void"
    },
    "m_3ec7d00e58c97dc9a75db22761e186f2": {
        "action": "App\\Http\\Controllers\\ShareListController@store",
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
        "version": "3ec7d00e58c97dc9a75db22761e186f26b8f0066723ad62ee7aa1b6e29d3ef0a",
        "returnType": "void"
    },
    "m_99ce22fc3246831e59df058def11c14e": {
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
        "version": "99ce22fc3246831e59df058def11c14ecdc83c22dafb9d255919cf27f15b0ae6",
        "returnType": "void"
    },
    "m_640d225a7810cbd17415c6f9342c97d0": {
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
        "version": "640d225a7810cbd17415c6f9342c97d0cd6c48c0c181c8360a6d01984429607c",
        "returnType": "array"
    },
    "m_94e0c20498029a89e470793a1def5e4d": {
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
        "version": "94e0c20498029a89e470793a1def5e4de15468fa4710e306fb2dd1be23e932c9",
        "returnType": "void"
    },
    "m_e7f494d11025bc4a9529622e4fbafd5b": {
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
        "version": "e7f494d11025bc4a9529622e4fbafd5b520d1ec28b7b4dcc13a08838b1b644ac",
        "returnType": "void"
    },
    "m_0455d56b2a24c88f580acb163f36b9e7": {
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
        "version": "0455d56b2a24c88f580acb163f36b9e734449cc512bf781b1a0a7962a4d939ee",
        "returnType": "array"
    },
    "m_0ba9c3b2056da26374ac691ff05b6b83": {
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
        "version": "0ba9c3b2056da26374ac691ff05b6b83564b3b164145f82ec075141e69dd303d",
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
export function createControllerMutationBindings<S extends Schema, C extends ClientContext = ClientContext>(schema: S, models: Readonly<Record<string, ModelContract>>) {
  const codecs = {
    "m_9a2016beb6e3f9b7eea8e8dfd916eaaf": {...codec0},
    "m_3ec7d00e58c97dc9a75db22761e186f2": {...codec1},
    "m_99ce22fc3246831e59df058def11c14e": {...codec2},
    "m_640d225a7810cbd17415c6f9342c97d0": {...codec3},
    "m_94e0c20498029a89e470793a1def5e4d": {...codec4},
    "m_e7f494d11025bc4a9529622e4fbafd5b": {...codec5},
    "m_0455d56b2a24c88f580acb163f36b9e7": {...codec6},
    "m_0ba9c3b2056da26374ac691ff05b6b83": {...codec7},
  };
  const runtime = createControllerMutations<S, C>(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "App\\Http\\Controllers\\ShareListController@destroy": runtime.bind("m_9a2016beb6e3f9b7eea8e8dfd916eaaf", codecs["m_9a2016beb6e3f9b7eea8e8dfd916eaaf"]),
    "App\\Http\\Controllers\\ShareListController@store": runtime.bind("m_3ec7d00e58c97dc9a75db22761e186f2", codecs["m_3ec7d00e58c97dc9a75db22761e186f2"]),
    "App\\Http\\Controllers\\TodoController@destroy": runtime.bind("m_99ce22fc3246831e59df058def11c14e", codecs["m_99ce22fc3246831e59df058def11c14e"]),
    "App\\Http\\Controllers\\TodoController@store": runtime.bind("m_640d225a7810cbd17415c6f9342c97d0", codecs["m_640d225a7810cbd17415c6f9342c97d0"]),
    "App\\Http\\Controllers\\TodoController@update": runtime.bind("m_94e0c20498029a89e470793a1def5e4d", codecs["m_94e0c20498029a89e470793a1def5e4d"]),
    "App\\Http\\Controllers\\TodoListController@destroy": runtime.bind("m_e7f494d11025bc4a9529622e4fbafd5b", codecs["m_e7f494d11025bc4a9529622e4fbafd5b"]),
    "App\\Http\\Controllers\\TodoListController@store": runtime.bind("m_0455d56b2a24c88f580acb163f36b9e7", codecs["m_0455d56b2a24c88f580acb163f36b9e7"]),
    "App\\Http\\Controllers\\TodoListController@update": runtime.bind("m_0ba9c3b2056da26374ac691ff05b6b83", codecs["m_0ba9c3b2056da26374ac691ff05b6b83"]),
  }};
}
