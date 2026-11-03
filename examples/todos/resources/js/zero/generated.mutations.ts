// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ClientContext, type ModelContract} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_fb12517f9e15822b1ebb45256b2e1ae2": {
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
        "version": "fb12517f9e15822b1ebb45256b2e1ae28ee96d86d2ac8791db300452e70ca546",
        "returnType": "void"
    },
    "m_5ffdc2c247eeb9f26d81b4dfa3330d23": {
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
        "version": "5ffdc2c247eeb9f26d81b4dfa3330d238c7b36c3ae57c20f48a45656cb4fd87e",
        "returnType": "void"
    },
    "m_edece6e788ca5b6f5bdc6051c7b884f7": {
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
        "version": "edece6e788ca5b6f5bdc6051c7b884f7539128724fc590e51204a301710eaabc",
        "returnType": "void"
    },
    "m_708922da5a66a37a158fc820729ed0af": {
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
        "version": "708922da5a66a37a158fc820729ed0aff688b9832e239a31375a89bcea48fce4",
        "returnType": "array"
    },
    "m_5b14b21f7c848d03d2c7a61881d9c7e4": {
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
        "version": "5b14b21f7c848d03d2c7a61881d9c7e4a3a822d3e8e110b51e5521f85f4992d3",
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
    "m_fb12517f9e15822b1ebb45256b2e1ae2": {...codec0},
    "m_5ffdc2c247eeb9f26d81b4dfa3330d23": {...codec1},
    "m_edece6e788ca5b6f5bdc6051c7b884f7": {...codec2},
    "m_708922da5a66a37a158fc820729ed0af": {...codec3},
    "m_5b14b21f7c848d03d2c7a61881d9c7e4": {...codec4},
    "m_e7f494d11025bc4a9529622e4fbafd5b": {...codec5},
    "m_0455d56b2a24c88f580acb163f36b9e7": {...codec6},
    "m_0ba9c3b2056da26374ac691ff05b6b83": {...codec7},
  };
  const runtime = createControllerMutations<S, C>(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "App\\Http\\Controllers\\ShareListController@__invoke": runtime.bind("m_fb12517f9e15822b1ebb45256b2e1ae2", codecs["m_fb12517f9e15822b1ebb45256b2e1ae2"]),
    "App\\Http\\Controllers\\ShareListController@destroy": runtime.bind("m_5ffdc2c247eeb9f26d81b4dfa3330d23", codecs["m_5ffdc2c247eeb9f26d81b4dfa3330d23"]),
    "App\\Http\\Controllers\\TodoController@destroy": runtime.bind("m_edece6e788ca5b6f5bdc6051c7b884f7", codecs["m_edece6e788ca5b6f5bdc6051c7b884f7"]),
    "App\\Http\\Controllers\\TodoController@store": runtime.bind("m_708922da5a66a37a158fc820729ed0af", codecs["m_708922da5a66a37a158fc820729ed0af"]),
    "App\\Http\\Controllers\\TodoController@update": runtime.bind("m_5b14b21f7c848d03d2c7a61881d9c7e4", codecs["m_5b14b21f7c848d03d2c7a61881d9c7e4"]),
    "App\\Http\\Controllers\\TodoListController@destroy": runtime.bind("m_e7f494d11025bc4a9529622e4fbafd5b", codecs["m_e7f494d11025bc4a9529622e4fbafd5b"]),
    "App\\Http\\Controllers\\TodoListController@store": runtime.bind("m_0455d56b2a24c88f580acb163f36b9e7", codecs["m_0455d56b2a24c88f580acb163f36b9e7"]),
    "App\\Http\\Controllers\\TodoListController@update": runtime.bind("m_0ba9c3b2056da26374ac691ff05b6b83", codecs["m_0ba9c3b2056da26374ac691ff05b6b83"]),
  }};
}
