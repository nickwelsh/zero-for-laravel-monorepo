// Generated controller mutations; deploy with the matching PHP manifest.
/* oxlint-disable unicorn/no-thenable -- Operation trees use a JSON then branch, never a callable then. */
import type {Schema} from '@rocicorp/zero';
import {createControllerMutations, type ClientContext, type ModelContract} from '@nickwelsh/zero-for-laravel';
import {z} from 'zod';
export const controllerMutationContracts = {
    "m_3439551a925d171fa3f8a37c940e63b3": {
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
        "version": "3439551a925d171fa3f8a37c940e63b32fe13c8aed801fe52ce47cde528d32be",
        "returnType": "void"
    },
    "m_1b2cd1117df53ae57d3ee164925c6b62": {
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
        "version": "1b2cd1117df53ae57d3ee164925c6b6254c3b0f3e1a3d69fc4f8dbc3ef19afa1",
        "returnType": "void"
    },
    "m_77dcca5755be0fb1a99a2cb34ce3bc53": {
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
        "version": "77dcca5755be0fb1a99a2cb34ce3bc530a6ac575eee5ba4ca7d23074abe54300",
        "returnType": "void"
    },
    "m_4db8b12df7c8e209f0c07c08e677d6a3": {
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
        "version": "4db8b12df7c8e209f0c07c08e677d6a34035cc364f8d70bfe840dc4780abecbf",
        "returnType": "array"
    },
    "m_f267bc7741a50fbef6024dcd0f9602d5": {
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
        "version": "f267bc7741a50fbef6024dcd0f9602d570dc6e62677fe283c62ad1f3ec5b03a1",
        "returnType": "void"
    },
    "m_8304615915f3b301c7f745ec6692b02e": {
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
        "version": "8304615915f3b301c7f745ec6692b02e9d3803a8890bdbff1097911fbe3aa693",
        "returnType": "void"
    },
    "m_2469dc0ac4a1cd1c6fe49d8e5e529276": {
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
        "version": "2469dc0ac4a1cd1c6fe49d8e5e529276a05bc6f7bbe913bba9a239d164448399",
        "returnType": "array"
    },
    "m_e305f732176f617431bb82bade637738": {
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
        "version": "e305f732176f617431bb82bade6377383fad349e8ac0d1712e1ff83f1e0a22b2",
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
    "m_3439551a925d171fa3f8a37c940e63b3": {...codec0},
    "m_1b2cd1117df53ae57d3ee164925c6b62": {...codec1},
    "m_77dcca5755be0fb1a99a2cb34ce3bc53": {...codec2},
    "m_4db8b12df7c8e209f0c07c08e677d6a3": {...codec3},
    "m_f267bc7741a50fbef6024dcd0f9602d5": {...codec4},
    "m_8304615915f3b301c7f745ec6692b02e": {...codec5},
    "m_2469dc0ac4a1cd1c6fe49d8e5e529276": {...codec6},
    "m_e305f732176f617431bb82bade637738": {...codec7},
  };
  const runtime = createControllerMutations<S, C>(schema, models, controllerMutationContracts, codecs);
  return {...runtime, actions: {
    "App\\Http\\Controllers\\ShareListController@destroy": runtime.bind("m_3439551a925d171fa3f8a37c940e63b3", codecs["m_3439551a925d171fa3f8a37c940e63b3"]),
    "App\\Http\\Controllers\\ShareListController@store": runtime.bind("m_1b2cd1117df53ae57d3ee164925c6b62", codecs["m_1b2cd1117df53ae57d3ee164925c6b62"]),
    "App\\Http\\Controllers\\TodoController@destroy": runtime.bind("m_77dcca5755be0fb1a99a2cb34ce3bc53", codecs["m_77dcca5755be0fb1a99a2cb34ce3bc53"]),
    "App\\Http\\Controllers\\TodoController@store": runtime.bind("m_4db8b12df7c8e209f0c07c08e677d6a3", codecs["m_4db8b12df7c8e209f0c07c08e677d6a3"]),
    "App\\Http\\Controllers\\TodoController@update": runtime.bind("m_f267bc7741a50fbef6024dcd0f9602d5", codecs["m_f267bc7741a50fbef6024dcd0f9602d5"]),
    "App\\Http\\Controllers\\TodoListController@destroy": runtime.bind("m_8304615915f3b301c7f745ec6692b02e", codecs["m_8304615915f3b301c7f745ec6692b02e"]),
    "App\\Http\\Controllers\\TodoListController@store": runtime.bind("m_2469dc0ac4a1cd1c6fe49d8e5e529276", codecs["m_2469dc0ac4a1cd1c6fe49d8e5e529276"]),
    "App\\Http\\Controllers\\TodoListController@update": runtime.bind("m_e305f732176f617431bb82bade637738", codecs["m_e305f732176f617431bb82bade637738"]),
  }};
}
