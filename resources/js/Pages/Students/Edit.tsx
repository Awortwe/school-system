import StudentForm, { type StudentInput } from './StudentForm';
import type { Option } from '../../types';
export default function Edit({ student, sections }: { student: StudentInput; sections: Option[] }) { return <StudentForm student={student} sections={sections} />; }
