// Generated Zero public API. Do not edit.
import type {ControllerMutationAction} from '@nickwelsh/zero-for-laravel';
import {inputSchema0, serverResultSchema0} from "../../../generated.mutations";
import type {Input0, ServerResult0} from "../../../generated.mutations";
const action0 = {action: "App\\Http\\Controllers\\ShareListController@__invoke", inputSchema: inputSchema0, serverResultSchema: serverResultSchema0} as const satisfies ControllerMutationAction<Input0, ServerResult0>;
export {action0 as __invoke};
export type {Input0 as __invokeInput, ServerResult0 as __invokeResult} from "../../../generated.mutations";
import {inputSchema1, serverResultSchema1} from "../../../generated.mutations";
import type {Input1, ServerResult1} from "../../../generated.mutations";
const action1 = {action: "App\\Http\\Controllers\\ShareListController@destroy", inputSchema: inputSchema1, serverResultSchema: serverResultSchema1} as const satisfies ControllerMutationAction<Input1, ServerResult1>;
export {action1 as destroy};
export type {Input1 as DestroyInput, ServerResult1 as DestroyResult} from "../../../generated.mutations";
