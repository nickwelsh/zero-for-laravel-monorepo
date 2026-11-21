// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ClientContext, type ModelContract} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_01a1b7de4a0bd25b2b86ad242cb39003": {
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
        "version": "01a1b7de4a0bd25b2b86ad242cb39003dccd76035b7b5431536b07b02a6ed096",
        "returnType": "void"
    },
    "m_035ff3eaa2e85a3e2b77524e42d1749e": {
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
        "version": "035ff3eaa2e85a3e2b77524e42d1749e364edef3f0c122e3738e519b7c4f2433",
        "returnType": "void"
    },
    "m_be9b60fa04f7e5c9cb8f2fd78eee38c9": {
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
        "version": "be9b60fa04f7e5c9cb8f2fd78eee38c943da9b6739e6dfe3ee7668daf43a7ad5",
        "returnType": "void"
    },
    "m_b197eac8dbc761cb5b20323220612616": {
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
        "version": "b197eac8dbc761cb5b20323220612616db41422754b83f4cc0821afbabe61032",
        "returnType": "array"
    },
    "m_df5cb0f85c49e9e9538c310d5c971b16": {
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
        "version": "df5cb0f85c49e9e9538c310d5c971b1666676179d18c142e97df5717f26d4f56",
        "returnType": "void"
    },
    "m_97734ef87866f3d5e44ab7d7686c5435": {
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
        "version": "97734ef87866f3d5e44ab7d7686c5435a94fc528678b77fe94bfa2acc007a8d0",
        "returnType": "void"
    },
    "m_477971a45fdd403c1243cdcfeb1a5425": {
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
        "version": "477971a45fdd403c1243cdcfeb1a5425b01e25fd085f3caa209d23adf1a21b0c",
        "returnType": "array"
    },
    "m_393c6f49ab702f21be9ccb6cf25898d2": {
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
        "version": "393c6f49ab702f21be9ccb6cf25898d24b8064b061bb3a0ad7451ee4ab645dc9",
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
const codec2 = {input: inputSchema2, encode: (_input: Input2) => ({}), serverResult: serverResultSchema2, clientResult: clientResultSchema2};
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
const codec5 = {input: inputSchema5, encode: (_input: Input5) => ({}), serverResult: serverResultSchema5, clientResult: clientResultSchema5};
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
    "m_01a1b7de4a0bd25b2b86ad242cb39003": {...codec0},
    "m_035ff3eaa2e85a3e2b77524e42d1749e": {...codec1},
    "m_be9b60fa04f7e5c9cb8f2fd78eee38c9": {...codec2},
    "m_b197eac8dbc761cb5b20323220612616": {...codec3},
    "m_df5cb0f85c49e9e9538c310d5c971b16": {...codec4},
    "m_97734ef87866f3d5e44ab7d7686c5435": {...codec5},
    "m_477971a45fdd403c1243cdcfeb1a5425": {...codec6},
    "m_393c6f49ab702f21be9ccb6cf25898d2": {...codec7},
  };
  const runtime = createControllerMutations<S, C>(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "App\\Http\\Controllers\\ShareListController@destroy": runtime.bind("m_01a1b7de4a0bd25b2b86ad242cb39003", codecs["m_01a1b7de4a0bd25b2b86ad242cb39003"]),
    "App\\Http\\Controllers\\ShareListController@store": runtime.bind("m_035ff3eaa2e85a3e2b77524e42d1749e", codecs["m_035ff3eaa2e85a3e2b77524e42d1749e"]),
    "App\\Http\\Controllers\\TodoController@destroy": runtime.bind("m_be9b60fa04f7e5c9cb8f2fd78eee38c9", codecs["m_be9b60fa04f7e5c9cb8f2fd78eee38c9"]),
    "App\\Http\\Controllers\\TodoController@store": runtime.bind("m_b197eac8dbc761cb5b20323220612616", codecs["m_b197eac8dbc761cb5b20323220612616"]),
    "App\\Http\\Controllers\\TodoController@update": runtime.bind("m_df5cb0f85c49e9e9538c310d5c971b16", codecs["m_df5cb0f85c49e9e9538c310d5c971b16"]),
    "App\\Http\\Controllers\\TodoListController@destroy": runtime.bind("m_97734ef87866f3d5e44ab7d7686c5435", codecs["m_97734ef87866f3d5e44ab7d7686c5435"]),
    "App\\Http\\Controllers\\TodoListController@store": runtime.bind("m_477971a45fdd403c1243cdcfeb1a5425", codecs["m_477971a45fdd403c1243cdcfeb1a5425"]),
    "App\\Http\\Controllers\\TodoListController@update": runtime.bind("m_393c6f49ab702f21be9ccb6cf25898d2", codecs["m_393c6f49ab702f21be9ccb6cf25898d2"]),
  }};
}
