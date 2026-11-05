// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ClientContext, type ModelContract} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_9f927d648939756dcbbee075e30eba5d": {
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
        "version": "9f927d648939756dcbbee075e30eba5d29dd962f870204bdb79796bae82ad1e7",
        "returnType": "void"
    },
    "m_60a9cb58597e606c6aa64f7f7d489e0e": {
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
        "version": "60a9cb58597e606c6aa64f7f7d489e0e5b81a6e3cb264cac9ab6d184a9118f1c",
        "returnType": "void"
    },
    "m_5b189e2788ab67c8d8da1720395f6ff3": {
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
        "version": "5b189e2788ab67c8d8da1720395f6ff352050a9b0555be37891e7299b5e674f3",
        "returnType": "void"
    },
    "m_049297649905238ce60c20f9af8f4f78": {
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
        "version": "049297649905238ce60c20f9af8f4f78a5ddf0982ff2bc8ca22809c7ebbfa473",
        "returnType": "array"
    },
    "m_b3ab3b596a5fa181a7d040d49c80888d": {
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
        "version": "b3ab3b596a5fa181a7d040d49c80888d50da83ebdd99abead08489b93ffc7cc1",
        "returnType": "void"
    },
    "m_6ff0d5060fafe6f2d5237dee7e359513": {
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
        "version": "6ff0d5060fafe6f2d5237dee7e3595131430fd5dbdbe799fa00c2a3e20856f3d",
        "returnType": "void"
    },
    "m_d33d67a67085faabf20f340c78518df1": {
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
        "version": "d33d67a67085faabf20f340c78518df15ee3a3dc259251e31eaa6213cdf752f2",
        "returnType": "array"
    },
    "m_40e12839f1a5e0e8d1bb1e25c0d1a1b7": {
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
        "version": "40e12839f1a5e0e8d1bb1e25c0d1a1b766d6525d2fe358e9d1c04f24482a414a",
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
    "m_9f927d648939756dcbbee075e30eba5d": {...codec0},
    "m_60a9cb58597e606c6aa64f7f7d489e0e": {...codec1},
    "m_5b189e2788ab67c8d8da1720395f6ff3": {...codec2},
    "m_049297649905238ce60c20f9af8f4f78": {...codec3},
    "m_b3ab3b596a5fa181a7d040d49c80888d": {...codec4},
    "m_6ff0d5060fafe6f2d5237dee7e359513": {...codec5},
    "m_d33d67a67085faabf20f340c78518df1": {...codec6},
    "m_40e12839f1a5e0e8d1bb1e25c0d1a1b7": {...codec7},
  };
  const runtime = createControllerMutations<S, C>(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "App\\Http\\Controllers\\ShareListController@destroy": runtime.bind("m_9f927d648939756dcbbee075e30eba5d", codecs["m_9f927d648939756dcbbee075e30eba5d"]),
    "App\\Http\\Controllers\\ShareListController@store": runtime.bind("m_60a9cb58597e606c6aa64f7f7d489e0e", codecs["m_60a9cb58597e606c6aa64f7f7d489e0e"]),
    "App\\Http\\Controllers\\TodoController@destroy": runtime.bind("m_5b189e2788ab67c8d8da1720395f6ff3", codecs["m_5b189e2788ab67c8d8da1720395f6ff3"]),
    "App\\Http\\Controllers\\TodoController@store": runtime.bind("m_049297649905238ce60c20f9af8f4f78", codecs["m_049297649905238ce60c20f9af8f4f78"]),
    "App\\Http\\Controllers\\TodoController@update": runtime.bind("m_b3ab3b596a5fa181a7d040d49c80888d", codecs["m_b3ab3b596a5fa181a7d040d49c80888d"]),
    "App\\Http\\Controllers\\TodoListController@destroy": runtime.bind("m_6ff0d5060fafe6f2d5237dee7e359513", codecs["m_6ff0d5060fafe6f2d5237dee7e359513"]),
    "App\\Http\\Controllers\\TodoListController@store": runtime.bind("m_d33d67a67085faabf20f340c78518df1", codecs["m_d33d67a67085faabf20f340c78518df1"]),
    "App\\Http\\Controllers\\TodoListController@update": runtime.bind("m_40e12839f1a5e0e8d1bb1e25c0d1a1b7", codecs["m_40e12839f1a5e0e8d1bb1e25c0d1a1b7"]),
  }};
}
