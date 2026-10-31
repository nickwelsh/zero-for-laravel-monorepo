// Generated Zero public API. Do not edit.
import type {ControllerMutationAction} from '@nickwelsh/zero-for-laravel';
import {inputSchema4, serverResultSchema4} from "../../../generated.mutations";
import type {Input4, ServerResult4} from "../../../generated.mutations";
const action4 = {action: "App\\Http\\Controllers\\TodoListController@destroy", inputSchema: inputSchema4, serverResultSchema: serverResultSchema4} as const satisfies ControllerMutationAction<Input4, ServerResult4>;
export {action4 as destroy};
export type {Input4 as DestroyInput, ServerResult4 as DestroyResult} from "../../../generated.mutations";
import {inputSchema5, serverResultSchema5} from "../../../generated.mutations";
import type {Input5, ServerResult5} from "../../../generated.mutations";
const action5 = {action: "App\\Http\\Controllers\\TodoListController@store", inputSchema: inputSchema5, serverResultSchema: serverResultSchema5} as const satisfies ControllerMutationAction<Input5, ServerResult5>;
export {action5 as store};
export type {Input5 as StoreInput, ServerResult5 as StoreResult} from "../../../generated.mutations";
import {inputSchema6, serverResultSchema6} from "../../../generated.mutations";
import type {Input6, ServerResult6} from "../../../generated.mutations";
const action6 = {action: "App\\Http\\Controllers\\TodoListController@update", inputSchema: inputSchema6, serverResultSchema: serverResultSchema6} as const satisfies ControllerMutationAction<Input6, ServerResult6>;
export {action6 as update};
export type {Input6 as UpdateInput, ServerResult6 as UpdateResult} from "../../../generated.mutations";
