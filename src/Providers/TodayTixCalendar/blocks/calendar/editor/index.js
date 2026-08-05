import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';
import metadata from '../block.json';
import '../style.scss';
import './editor.scss';

// Dynamic block — markup is owned by render.php / calendar.twig. The editor uses
// ServerSideRender to mirror that markup, so save() returns null (the server is the
// single source of truth). Without this client-side registration the editor reports
// "your site doesn't include support for this block".
registerBlockType(metadata.name, {
    ...metadata,
    edit: Edit,
    save: () => null,
});
