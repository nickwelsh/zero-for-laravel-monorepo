// Generated Zero public API. Do not edit.
import type {ControllerMutationAction} from '@nickwelsh/zero-for-laravel';
import {inputSchema2, serverResultSchema2} from "../../../generated.mutations";
import type {Input2, ServerResult2} from "../../../generated.mutations";
const action2 = {action: "App\\Http\\Controllers\\TodoController@destroy", inputSchema: inputSchema2, serverResultSchema: serverResultSchema2} as const satisfies ControllerMutationAction<Input2, ServerResult2>;
export {action2 as destroy};
export type {Input2 as DestroyInput, ServerResult2 as DestroyResult} from "../../../generated.mutations";
import {inputSchema3, serverResultSchema3} from "../../../generated.mutations";
import type {Input3, ServerResult3} from "../../../generated.mutations";
const action3 = {action: "App\\Http\\Controllers\\TodoController@store", inputSchema: inputSchema3, serverResultSchema: serverResultSchema3} as const satisfies ControllerMutationAction<Input3, ServerResult3>;
export {action3 as store};
export type {Input3 as StoreInput, ServerResult3 as StoreResult} from "../../../generated.mutations";
import {inputSchema4, serverResultSchema4} from "../../../generated.mutations";
import type {Input4, ServerResult4} from "../../../generated.mutations";
const action4 = {action: "App\\Http\\Controllers\\TodoController@update", inputSchema: inputSchema4, serverResultSchema: serverResultSchema4} as const satisfies ControllerMutationAction<Input4, ServerResult4>;
export {action4 as update};
export type {Input4 as UpdateInput, ServerResult4 as UpdateResult} from "../../../generated.mutations";
