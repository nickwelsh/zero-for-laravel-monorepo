import { defineConfig } from '@formbraid/react';
import { CheckboxField, HiddenField, TextField } from './fields';
import { CreateListButton, SubmitButton } from './form-components';

export const { Form, useForm } = defineConfig({
    components: { TextField, CheckboxField, HiddenField },
    formComponents: { SubmitButton, CreateListButton },
    submitComponent: 'CreateListButton',
});
