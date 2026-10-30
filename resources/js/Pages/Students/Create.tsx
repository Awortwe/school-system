import StudentForm from './StudentForm';
import type { Option } from '../../types';
export default function Create({ sections }: { sections: Option[] }) { return <StudentForm sections={sections} />; }
