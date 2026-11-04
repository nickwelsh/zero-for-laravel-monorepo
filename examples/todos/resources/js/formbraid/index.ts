import { defineConfig } from '@formbraid/react';
import { CheckboxField, ComboboxField, HiddenField, TextField } from './fields';
import { CreateListButton, SubmitButton } from './form-components';

export const { Form, useForm } = defineConfig({
    components: { TextField, CheckboxField, HiddenField, ComboboxField },
    formComponents: { SubmitButton, CreateListButton },
    submitComponent: 'CreateListButton',
});
