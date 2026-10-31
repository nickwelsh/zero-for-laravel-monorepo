// Generated Zero public API. Do not edit.
import type {ControllerMutationAction} from '@nickwelsh/zero-for-laravel';
import {inputSchema1, serverResultSchema1} from "../../../generated.mutations";
import type {Input1, ServerResult1} from "../../../generated.mutations";
const action1 = {action: "App\\Http\\Controllers\\TodoController@destroy", inputSchema: inputSchema1, serverResultSchema: serverResultSchema1} as const satisfies ControllerMutationAction<Input1, ServerResult1>;
export {action1 as destroy};
export type {Input1 as DestroyInput, ServerResult1 as DestroyResult} from "../../../generated.mutations";
import {inputSchema2, serverResultSchema2} from "../../../generated.mutations";
import type {Input2, ServerResult2} from "../../../generated.mutations";
const action2 = {action: "App\\Http\\Controllers\\TodoController@store", inputSchema: inputSchema2, serverResultSchema: serverResultSchema2} as const satisfies ControllerMutationAction<Input2, ServerResult2>;
export {action2 as store};
export type {Input2 as StoreInput, ServerResult2 as StoreResult} from "../../../generated.mutations";
import {inputSchema3, serverResultSchema3} from "../../../generated.mutations";
import type {Input3, ServerResult3} from "../../../generated.mutations";
const action3 = {action: "App\\Http\\Controllers\\TodoController@update", inputSchema: inputSchema3, serverResultSchema: serverResultSchema3} as const satisfies ControllerMutationAction<Input3, ServerResult3>;
export {action3 as update};
export type {Input3 as UpdateInput, ServerResult3 as UpdateResult} from "../../../generated.mutations";
