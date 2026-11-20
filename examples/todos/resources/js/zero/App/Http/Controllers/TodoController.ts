// Generated Zero public API. Do not edit.
import {controllerForm, type ControllerMutationAction} from '@nickwelsh/zero-for-laravel';
import {inputSchema2, serverResultSchema2} from "../../../generated.mutations";
import type {Input2, ServerResult2} from "../../../generated.mutations";
const action2 = controllerForm({action: "App\\Http\\Controllers\\TodoController@destroy", inputSchema: inputSchema2, serverResultSchema: serverResultSchema2} as const satisfies ControllerMutationAction<Input2, ServerResult2>, [["todo","id"]] as const, {});
export {action2 as destroy};
export type {Input2 as DestroyInput, ServerResult2 as DestroyResult} from "../../../generated.mutations";
import {inputSchema3, serverResultSchema3} from "../../../generated.mutations";
import type {Input3, ServerResult3} from "../../../generated.mutations";
const action3 = controllerForm({action: "App\\Http\\Controllers\\TodoController@store", inputSchema: inputSchema3, serverResultSchema: serverResultSchema3} as const satisfies ControllerMutationAction<Input3, ServerResult3>, [] as const, {"list_id":{"kind":"string","required":true},"title":{"kind":"string","required":true},"completed":{"kind":"bool"}});
export {action3 as store};
export type {Input3 as StoreInput, ServerResult3 as StoreResult} from "../../../generated.mutations";
import {inputSchema4, serverResultSchema4} from "../../../generated.mutations";
import type {Input4, ServerResult4} from "../../../generated.mutations";
const action4 = controllerForm({action: "App\\Http\\Controllers\\TodoController@update", inputSchema: inputSchema4, serverResultSchema: serverResultSchema4} as const satisfies ControllerMutationAction<Input4, ServerResult4>, [["todo","id"]] as const, {"list_id":{"kind":"string","required":true},"title":{"kind":"string","required":true},"completed":{"kind":"bool"}});
export {action4 as update};
export type {Input4 as UpdateInput, ServerResult4 as UpdateResult} from "../../../generated.mutations";
export default {destroy: action2, store: action3, update: action4};
